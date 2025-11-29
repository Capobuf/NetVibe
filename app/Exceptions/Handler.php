<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Psr\Log\LoggerInterface;
use Throwable;
use Livewire\Exceptions\RootTagMissingFromViewException;

class Handler extends ExceptionHandler implements ExceptionHandlerContract
{
    /**
     * Report or log an exception.
     */
    public function report(Throwable $e): void
    {
        parent::report($e);
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        if ($e instanceof RootTagMissingFromViewException) {
            // log helpful diagnostics to aid debugging: include request content so we can find the failing Livewire component
            try {
                $logger = app(LoggerInterface::class);
                $logger->error('[RootTagMissingFromViewException] Livewire root tag missing while rendering component', [
                    'uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                    'body' => $request->getContent(),
                    'headers' => $request->headers->all(),
                ]);
            } catch (Throwable $logEx) {
                // swallow any logging errors — we don't want to mask the original exception
            }
        }

        return parent::render($request, $e);
    }
}
