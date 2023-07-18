<?php

declare(strict_types=1);

namespace GuzzleLogMiddleware\Handler;

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\TransferStats;
use GuzzleLogMiddleware\Handler\LogLevelStrategy\LogLevelStrategyInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author George Mponos <gmponos@gmail.com>
 */
final class MultiRecordArrayHandler extends AbstractHandler
{
    /**
     * @var int
     */
    private $truncateSize;

    /**
     * @var int
     */
    private $summarySize;

    private $logContext = [];

    /**
     * @param int $truncateSize If the body of the request/response is greater than the size of this integer the body will be truncated
     * @param int $summarySize The size to use for the summary of a truncated body
     */
    public function __construct(
        LogLevelStrategyInterface $logLevelStrategy = null,
        int $truncateSize = 3500,
        int $summarySize = 200
    ) {
        $this->logLevelStrategy = $logLevelStrategy === null ? $this->getDefaultStrategy() : $logLevelStrategy;
        $this->truncateSize = $truncateSize;
        $this->summarySize = $summarySize;
    }

    public function log(
        LoggerInterface $logger,
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?Throwable $exception = null,
        ?TransferStats $stats = null,
        array $options = []
    ): void {
        $this->logRequest($logger, $request, $options);

        if ($stats !== null) {
            $this->logStats($logger, $stats, $options);
        }

        if ($response !== null) {
            $this->logResponse($logger, $response, $options);
        } else {
            $this->logReason($logger, $exception, $options);
        }

//        $level = $this->logLevelStrategy->getLevel($request, $options);
        $logger->info('Guzzle HTTP request', $this->logContext);

    }

    private function logRequest(LoggerInterface $logger, RequestInterface $request, array $options): void
    {
        $this->logContext['request']['method'] = $request->getMethod();
        $this->logContext['request']['headers'] = $request->getHeaders();
        $this->logContext['request']['uri'] = $request->getRequestTarget();
        $this->logContext['request']['version'] = 'HTTP/' . $request->getProtocolVersion();

        if ($request->getBody()->getSize() > 0) {
            $this->logContext['request']['body'] = $this->formatBody($request, $options);
        }

//        $level = $this->logLevelStrategy->getLevel($request, $options);
//        $logger->log($level, 'Guzzle HTTP request', $context);
    }

    private function logResponse(LoggerInterface $logger, ResponseInterface $response, array $options): void
    {
        $this->logContext['response']['headers'] = $response->getHeaders();
        $this->logContext['response']['status_code'] = $response->getStatusCode();
        $this->logContext['response']['version'] = 'HTTP/' . $response->getProtocolVersion();
        $this->logContext['response']['message'] = $response->getReasonPhrase();

        if ($response->getBody()->getSize() > 0) {
            $this->logContext['response']['body'] = $this->formatBody($response, $options);
        }

//        $level = $this->logLevelStrategy->getLevel($response, $options);
//        $logger->log($level, 'Guzzle HTTP response', $context);
    }

    private function logReason(LoggerInterface $logger, ?Throwable $exception, array $options): void
    {
        if ($exception === null) {
            return;
        }

        $this->logContext['reason']['code'] = $exception->getCode();
        $this->logContext['reason']['message'] = $exception->getMessage();
        $this->logContext['reason']['line'] = $exception->getLine();
        $this->logContext['reason']['file'] = $exception->getFile();

//        $level = $this->logLevelStrategy->getLevel($exception, $options);
//        $logger->log($level, 'Guzzle HTTP exception', $context);
    }

    private function logStats(LoggerInterface $logger, TransferStats $stats, array $options): void
    {
        $this->logLevelStrategy->getLevel($stats, $options);
        $logger->debug('Guzzle HTTP statistics', [
            'time' => $stats->getTransferTime(),
            'uri' => $stats->getEffectiveUri(),
        ]);
    }

    /**
     * @return string|array
     */
    private function formatBody(MessageInterface $message, array $options)
    {
        $stream = $message->getBody();
        if ($stream->isSeekable() === false || $stream->isReadable() === false) {
            return 'Body stream is not seekable/readable.';
        }

        if (isset($options['log']['sensitive']) && $options['log']['sensitive'] === true) {
            return 'Body contains sensitive information therefore it is not included.';
        }

        if ($stream->getSize() >= $this->truncateSize) {
            $summary = $stream->read($this->summarySize) . ' (truncated...)';
            $stream->rewind();
            return $summary;
        }

        $body = $stream->__toString();
        $contentType = $message->getHeader('Content-Type');

        $isJson = preg_grep('/application\/[\w\.\+]*(json)/', $contentType);
        if (!empty($isJson)) {
            $result = json_decode($body, true);
            $stream->rewind();
            return $result;
        }

        $isForm = preg_grep('/application\/x-www-form-urlencoded/', $contentType);
        if (!empty($isForm)) {
            $result = Query::parse($body);
            $stream->rewind();
            return $result;
        }

        $stream->rewind();
        return $body;
    }
}
