<?php

namespace justinholtweb\shipper\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\shipper\Plugin;
use yii\console\ExitCode;

/**
 * Housekeeping for the connection log. Point cron at `shipper/log/prune`.
 */
class LogController extends Controller
{
    /**
     * Days of history to keep. Defaults to the configured retention.
     */
    public ?int $days = null;

    /**
     * Filter the listing by action: export, shipnotify, rates, refresh, carriers.
     */
    public ?string $action_filter = null;

    /**
     * Filter the listing by level: info, warning, error.
     */
    public ?string $level = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['days', 'action_filter', 'level']);
    }

    /**
     * Delete entries older than the retention window.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune($this->days);

        $this->stdout("Removed {$deleted} log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete every entry.
     */
    public function actionClear(): int
    {
        if (!$this->confirm('Delete the entire Shipper connection log?')) {
            return ExitCode::OK;
        }

        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->stdout("Removed {$deleted} log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Show recent entries.
     */
    public function actionList(): int
    {
        $entries = Plugin::getInstance()->getLog()->getEntries([
            'action' => $this->action_filter,
            'level' => $this->level,
        ], 50);

        if ($entries === []) {
            $this->stdout("No log entries.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach ($entries as $entry) {
            $color = match ($entry->level) {
                'error' => Console::FG_RED,
                'warning' => Console::FG_YELLOW,
                default => Console::FG_GREEN,
            };

            $this->stdout(sprintf(
                "%-20s %-12s %-4s %6s  %s\n",
                $entry->dateCreated?->format('Y-m-d H:i:s') ?? '',
                $entry->action,
                $entry->statusCode ?? '—',
                $entry->durationMs !== null ? $entry->durationMs . 'ms' : '—',
                $entry->summary ?? ''
            ), $color);
        }

        return ExitCode::OK;
    }
}
