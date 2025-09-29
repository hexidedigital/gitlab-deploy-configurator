<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class TelegramHandler extends AbstractProcessingHandler
{
    public const BASE_URI = 'https://api.telegram.org';

    /**
     * @param string $token Telegram bot API token
     * @param int|string $chatId Chat ID to which logs will be sent
     * @param int|string|null $treadId Thread ID to which logs will be sent
     * @param int|string|Level $level The minimum logging level at which this handler will be triggered
     * @param bool $bubble Whether the messages that are handled can bubble up the stack or not
     * @param bool $useCurl Whether to use cURL extension when available or not
     * @param int $timeout Maximum time to wait for requests to finish
     * @param bool $verifyPeer Whether to use SSL certificate verification or not
     */
    public function __construct(
        private readonly string $token,
        private readonly int|string $chatId,
        private readonly int|string|null $treadId = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly bool $useCurl = true,
        private readonly int $timeout = 10,
        private readonly bool $verifyPeer = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $message = $record['formatted'] ?? $record['message'];

        // When message is too long we have to remove HTML tags so that the message can be properly split
        if (mb_strlen($message, 'UTF-8') > 4096) {
            $message = strip_tags($message);
        }

        // Split the message and send it in parts when needed
        do {
            $message_part = mb_substr($message, 0, 4096);
            $this->send($message_part);
            $message = mb_substr($message, 4096);
        } while ($message !== '');
    }

    public function handleBatch(array $records): void
    {
        $messages = [];
        foreach ($records as $record) {
            if ($record['level'] < $this->level) {
                continue;
            }

            $messages[] = $this->processRecord($record);
        }

        if (!empty($messages)) {
            $datetime = new \DateTimeImmutable();

            $this->write(
                new LogRecord(
                    $datetime,
                    '',
                    Level::Debug,
                    '',
                    [],
                    [],
                    $this->getFormatter()->formatBatch($messages),
                ),
            );
        }
    }

    /**
     * Send sendMessage request to Telegram Bot API
     *
     * @param string $message The message to send
     */
    private function send(string $message): bool
    {
        $url = self::BASE_URI . '/bot' . $this->token . '/sendMessage';
        $data = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'disable_web_page_preview' => true, // Just in case there is a link in the message
        ];

        if ($this->treadId) {
            $data['reply_to_message_id'] = $this->treadId;
        }

        // Set HTML parse mode when HTML code is detected
        if (preg_match('/<[^<]+>/', $data['text']) !== false) {
            $data['parse_mode'] = 'HTML';
        }

        if ($this->useCurl === true && extension_loaded('curl')) {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

            $result = curl_exec($ch);
            if (!$result) {
                throw new \RuntimeException('Request to Telegram API failed: ' . curl_error($ch));
            }
        } else {
            $opts = [
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-type: application/x-www-form-urlencoded',
                    'content' => http_build_query($data),
                    'timeout' => $this->timeout,
                ],
                'ssl' => [
                    'verify_peer' => $this->verifyPeer,
                    'verify_peer_name' => $this->verifyPeer,
                ],
            ];

            $result = @file_get_contents($url, false, stream_context_create($opts));
            if (!$result) {
                $error = error_get_last();
                if (isset($error['message'])) {
                    throw new \RuntimeException('Request to Telegram API failed: ' . $error['message']);
                }

                throw new \RuntimeException('Request to Telegram API failed');
            }
        }

        $result = json_decode($result, true);
        if (isset($result['ok']) && $result['ok'] === true) {
            return true;
        }

        if (isset($result['description'])) {
            throw new \RuntimeException('Telegram API error: ' . $result['description']);
        }

        return false;
    }
}
