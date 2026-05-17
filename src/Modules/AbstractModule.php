<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules;

use LRob\EmailToolkit\Container;

abstract class AbstractModule implements ModuleInterface
{
    public function __construct(protected Container $container)
    {
    }

    public function requires(): array
    {
        return [];
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    /** Option key holding this module's settings. */
    final public function settings_option_key(): string
    {
        return 'lrob_etk_' . $this->slug() . '_settings';
    }

    /** Option key tracking the installed schema version for this module. */
    final public function db_version_option_key(): string
    {
        return 'lrob_etk_' . $this->slug() . '_db_version';
    }
}
