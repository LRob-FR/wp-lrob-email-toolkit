<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Support\Events;

/**
 * Daily nudge for pending subscribers who haven't confirmed yet.
 * Schedules a wp-cron event on enable, unschedules on disable.
 *
 * Pipeline:
 *   1. Read settings: max reminders, first-after-days, interval days.
 *   2. If max = 0, do nothing — feature off.
 *   3. Resolve the default reminder template; bail if none exists.
 *   4. Query pending subscribers due for a nudge (handled by the
 *      repo's list_pending_for_reminder).
 *   5. For each: render via TemplateRenderer with confirm/refuse
 *      tokens, wp_mail it, record the send.
 *   6. Fire newsletter.subscriber.reminder_sent per dispatch.
 *
 * Each cron tick handles a bounded batch (default 50) so a backlog
 * doesn't lock the request. The pending-followup index on the
 * subscribers table keeps the scan O(log n).
 */
final class ReminderCron
{
    public const CRON_HOOK = 'lrob_etk_nl_pending_followup';

    public const OPTION_MAX                = 'lrob_etk_nl_reminder_max';

    public const OPTION_FIRST_AFTER_DAYS   = 'lrob_etk_nl_first_reminder_after_days';

    public const OPTION_INTERVAL_DAYS      = 'lrob_etk_nl_reminder_interval_days';

    private const DEFAULT_MAX              = 2;

    private const DEFAULT_FIRST_AFTER_DAYS = 3;

    private const DEFAULT_INTERVAL_DAYS    = 7;

    private const BATCH_SIZE               = 50;

    public function __construct(private SubscriberRepository $subscribers)
    {
    }

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'handle_tick']);
    }

    /** Called from Module::install — schedule the daily tick if not already set. */
    public static function schedule(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK) === false) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /** Called from Module::uninstall / disable — clear every queued tick. */
    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public function handle_tick(): void
    {
        $max = (int) get_option(self::OPTION_MAX, self::DEFAULT_MAX);
        if ($max <= 0) {
            return;
        }
        $first_after = max(1, (int) get_option(self::OPTION_FIRST_AFTER_DAYS, self::DEFAULT_FIRST_AFTER_DAYS));
        $interval    = max(1, (int) get_option(self::OPTION_INTERVAL_DAYS, self::DEFAULT_INTERVAL_DAYS));

        $template_id = (new TemplateRepository())->default_id_for_purpose(TemplateCPT::PURPOSE_REMINDER);
        if ($template_id <= 0) {
            return;
        }

        $candidates = $this->subscribers->list_pending_for_reminder($first_after, $interval, $max, self::BATCH_SIZE);
        foreach ($candidates as $row) {
            $this->send_reminder($row, $template_id);
        }
    }

    /**
     * Render the reminder template for one subscriber and send via
     * wp_mail. Records the send on success; silently no-ops on
     * failure (admin sees nothing; reminder will retry on a later
     * tick after the interval elapses).
     *
     * @param array<string, mixed> $row
     */
    private function send_reminder(array $row, int $template_id): void
    {
        $subscriber_id = (int) ($row['id'] ?? 0);
        $email = (string) ($row['email'] ?? '');
        $name = (string) ($row['name'] ?? '');
        $prefs_token = (string) ($row['prefs_token'] ?? '');
        if ($subscriber_id <= 0 || $email === '' || !is_email($email)) {
            return;
        }

        $confirm_token = ConfirmationTokens::generate($subscriber_id, ConfirmationTokens::ACTION_CONFIRM);
        $refuse_token  = ConfirmationTokens::generate($subscriber_id, ConfirmationTokens::ACTION_REFUSE);
        $tokens = [
            'confirm_url' => add_query_arg('lrob-etk-nl-confirm', $confirm_token, home_url('/')),
            'refuse_url'  => add_query_arg('lrob-etk-nl-refuse',  $refuse_token,  home_url('/')),
            'prefs_url'   => $prefs_token !== '' ? add_query_arg(PrefsHandler::QUERY_PREFS, $prefs_token, home_url('/')) : '',
            'name'        => $name !== '' ? $name : $email,
            'first_name'  => self::first_name($name),
            'email'       => $email,
            'site_name'   => (string) get_bloginfo('name'),
            'site_url'    => (string) home_url('/'),
        ];

        $body_html = TemplateRenderer::render($template_id, $tokens);
        if ($body_html === '') {
            return;
        }
        $template_post = get_post($template_id);
        $subject = $template_post instanceof \WP_Post && $template_post->post_title !== ''
            ? $template_post->post_title
            : sprintf(
                /* translators: %s: site name. */
                __('Reminder: confirm your subscription to %s', 'lrob-email-toolkit'),
                (string) get_bloginfo('name')
            );
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'X-Lrob-Etk-Newsletter-Reminder: 1',
        ];
        if ($prefs_token !== '') {
            $unsub_url = add_query_arg(PrefsHandler::QUERY_UNSUB, $prefs_token, home_url('/'));
            $headers[] = 'List-Unsubscribe: <' . $unsub_url . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }

        $sent = wp_mail($email, $subject, $body_html, $headers);
        if (!$sent) {
            return;
        }
        $this->subscribers->record_reminder_sent($subscriber_id);
        Events::dispatch('newsletter.subscriber.reminder_sent', [
            'subscriber_id' => $subscriber_id,
            'email'         => $email,
            'reminder_n'    => ((int) ($row['reminder_count'] ?? 0)) + 1,
        ]);
    }

    private static function first_name(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $name, 2);
        return is_array($parts) ? (string) $parts[0] : $name;
    }
}
