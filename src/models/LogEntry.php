<?php

namespace justinholtweb\shipper\models;

use craft\base\Model;
use DateTime;

/**
 * One row in the connection log.
 */
class LogEntry extends Model
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public ?int $id = null;

    /**
     * `export`, `shipnotify`, `rates`, `refresh` or `carriers`.
     */
    public string $action = '';

    public string $level = self::LEVEL_INFO;
    public ?int $statusCode = null;
    public ?int $durationMs = null;
    public ?string $ip = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $request = null;
    public ?string $response = null;
    public ?DateTime $dateCreated = null;
    public ?string $uid = null;
}
