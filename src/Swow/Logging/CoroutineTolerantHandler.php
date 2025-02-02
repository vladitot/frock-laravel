<?php

namespace FrockDev\ToolsForLaravel\Swow\Logging;

use FrockDev\ToolsForLaravel\Swow\ContextStorage;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

class CoroutineTolerantHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        $severity = $record->level->getName();
        $message = $record->message;
        $context = $record->context ?? [];
        if (ContextStorage::get('x-trace-id')) {
            $context['x-trace-id'] = ContextStorage::get('x-trace-id');
        }
        $context['ProcessName'] = ContextStorage::getCurrentRoutineName();
        $context = array_merge($context, ContextStorage::getLogContext());
        ContextStorage::getSystemChannel('log')->push(
            new LogMessage(
                $severity,
                $message,
                $context
            )
        );
    }
}
