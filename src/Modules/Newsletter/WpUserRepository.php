<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Paginated read-side for WP users with newsletter prefs. Mirrors
 * SubscriberRepository's shape so the WP-users admin tab can reuse
 * the same filter-bar / region-swap idiom.
 *
 * Opt-in model is opt-OUT: a WP user without `lrob_etk_nl_opted_in`
 * user_meta counts as eligible. Explicit '0' means opted out.
 *
 * No write methods here — opt-in toggle goes through the AjaxController
 * which writes user_meta directly (single primitive).
 */
final class WpUserRepository
{
    /**
     * @param array{search?:string, opt_status?:string, list_id?:int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $per_page, int $offset): array
    {
        $args = self::query_args($filters);
        $args['number'] = $per_page;
        $args['offset'] = $offset;
        $args['orderby'] = 'registered';
        $args['order']   = 'DESC';
        $users = get_users($args);
        $rows = [];
        foreach (is_array($users) ? $users : [] as $u) {
            $rows[] = self::row_from_user($u);
        }
        return $rows;
    }

    /** @param array{search?:string, opt_status?:string, list_id?:int} $filters */
    public function count(array $filters): int
    {
        $args = self::query_args($filters);
        $args['count_total'] = true;
        $args['fields'] = 'ID';
        $args['number'] = 1;
        $q = new \WP_User_Query($args);
        return (int) $q->get_total();
    }

    /** @return array<string, int> opt_status → count */
    public function counts_by_opt_status(): array
    {
        $counts = [
            'all'        => $this->count([]),
            'opted_in'   => $this->count(['opt_status' => 'in']),
            'opted_out'  => $this->count(['opt_status' => 'out']),
            'bounced'    => $this->count(['opt_status' => 'bounced']),
        ];
        return $counts;
    }

    /**
     * @param array{search?:string, opt_status?:string, list_id?:int} $filters
     * @return array<string, mixed>
     */
    private static function query_args(array $filters): array
    {
        $args = [];
        if (!empty($filters['search']) && is_string($filters['search'])) {
            $term = trim($filters['search']);
            if ($term !== '') {
                $args['search']         = '*' . esc_attr($term) . '*';
                $args['search_columns'] = ['user_email', 'user_login', 'user_nicename', 'display_name'];
            }
        }
        $opt = (string) ($filters['opt_status'] ?? '');
        $meta_query = [];
        if ($opt === 'in') {
            // Opt-OUT model: explicit '1' OR no meta key counts as in.
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => UserMeta::OPTED_IN, 'value' => '1'],
                ['key' => UserMeta::OPTED_IN, 'compare' => 'NOT EXISTS'],
            ];
            // Exclude users flagged bounced/refused.
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => UserMeta::STATUS, 'value' => ['bounced', 'refused'], 'compare' => 'NOT IN'],
                ['key' => UserMeta::STATUS, 'compare' => 'NOT EXISTS'],
            ];
        } elseif ($opt === 'out') {
            $meta_query[] = ['key' => UserMeta::OPTED_IN, 'value' => '0'];
        } elseif ($opt === 'bounced') {
            $meta_query[] = ['key' => UserMeta::STATUS, 'value' => 'bounced'];
        }
        if ($meta_query !== []) {
            $args['meta_query'] = $meta_query;
        }
        // List filter: union of manual `list_members` rows
        // (recipient_kind='user') AND rule-resolved IDs when the list
        // is a users-kind rule list. `include` is an exact whitelist —
        // empty array would return everything, so we force [0] to mean
        // "no match" instead.
        $list_id = (int) ($filters['list_id'] ?? 0);
        if ($list_id > 0) {
            global $wpdb;
            $tbl = Schema::list_members_table();
            $manual = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT recipient_id FROM `$tbl` WHERE recipient_kind = %s AND list_id = %d",
                UserMeta::KIND_USER,
                $list_id
            ));
            $rule = (new ListRepository())->resolve_rule_user_ids($list_id);
            $ids = array_values(array_unique(array_map('intval', array_merge($manual, $rule))));
            $args['include'] = $ids === [] ? [0] : $ids;
        }
        return $args;
    }

    /**
     * Single-row lookup. Returns the same normalized shape as
     * row_from_user, or null when the id doesn't resolve.
     * @return array<string, mixed>|null
     */
    public function find_by_id(int $id): ?array
    {
        if ($id <= 0) return null;
        $u = get_userdata($id);
        return $u instanceof \WP_User ? self::row_from_user($u) : null;
    }

    /** @return array<string, mixed> */
    public static function row_from_user(\WP_User $u): array
    {
        $id = (int) $u->ID;
        $opted_raw = (string) get_user_meta($id, UserMeta::OPTED_IN, true);
        $status_raw = (string) get_user_meta($id, UserMeta::STATUS, true);
        // Effective state: explicit '0' → out; explicit bounced/refused → that status; else in.
        if ($status_raw === 'bounced')  $effective = 'bounced';
        elseif ($status_raw === 'refused') $effective = 'refused';
        elseif ($opted_raw === '0')     $effective = 'opted_out';
        else                            $effective = 'opted_in';

        return [
            'ID'           => $id,
            'email'        => (string) $u->user_email,
            'display_name' => (string) $u->display_name,
            'roles'        => array_values((array) $u->roles),
            'registered'   => (string) $u->user_registered,
            'opted_in'     => ($opted_raw !== '0'),
            'opted_raw'    => $opted_raw,
            'effective_status' => $effective,
            'status'       => $status_raw,
            'total_sent'       => (int) get_user_meta($id, UserMeta::TOTAL_SENT, true),
            'total_opened'     => (int) get_user_meta($id, UserMeta::TOTAL_OPENED, true),
            'total_clicked'    => (int) get_user_meta($id, UserMeta::TOTAL_CLICKED, true),
            'last_sent_at'     => (string) get_user_meta($id, UserMeta::LAST_SENT_AT, true),
            'last_engagement_at' => (string) get_user_meta($id, UserMeta::LAST_ENGAGEMENT_AT, true),
            'bounce_count'     => (int) get_user_meta($id, UserMeta::BOUNCE_COUNT, true),
            'confirmed_at'     => (string) get_user_meta($id, UserMeta::CONFIRMED_AT, true),
            'source'           => (string) get_user_meta($id, UserMeta::SOURCE, true),
        ];
    }
}
