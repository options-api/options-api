<?php

declare(strict_types=1);

namespace OptionsAPI;

class OptionsAPI
{
    private const SERVER_URL = 'https://optionsapi.com/api';
    public const FETCH_ASSOC = 0;
    public const FETCH_NUM = 1;

    private int $fetch_mode = self::FETCH_ASSOC;
    private int $last_modified = 0;
    private readonly \CurlHandle $ch;

    public function __construct(
        #[\SensitiveParameter]
        private ?string $token = null
    ) {
        $this->ch = curl_init();

        curl_setopt_array(
            $this->ch,
            [
                CURLOPT_ENCODING       => '',
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_RETURNTRANSFER => 1,
            ]
        );
    }

    private function exec(array $paths = [], ?int $last_modified = null): array|ResponseStatus
    {
        curl_setopt(
            $this->ch,
            CURLOPT_HTTPHEADER,
            $last_modified
                ? [sprintf('If-Modified-Since: %s', gmdate(\DateTimeInterface::RFC7231, $last_modified))]
                : []
        );

        $i = 0;

        do {
            $headers = [];

            curl_setopt_array(
                $this->ch,
                [
                    CURLOPT_HEADERFUNCTION => function (\CurlHandle $ch, string $header) use (&$headers): int {
                        $headers += array_column([explode(': ', trim($header), 2)], 1, 0);

                        return strlen($header);
                    },
                    CURLOPT_URL => $this->formatRequestURL($paths)
                ]
            );

            sleep($i++ ** 2);

            $result = curl_exec($this->ch);
            $logger = fn(string $message): int => printf(
                "[%s][%s] %s\n",
                Date('g:i:sA'),
                parse_url(curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL), PHP_URL_PATH),
                $message
            );

            if ($result === false) {
                $logger(sprintf('%s: %s', curl_strerror(curl_errno($this->ch)), curl_error($this->ch)));

                continue;
            }

            $immutable = \DateTimeImmutable::createFromFormat(
                \DateTimeInterface::RFC7231,
                $headers['last-modified'] ?? '',
                new \DateTimeZone('UTC')
            );

            $this->last_modified = $immutable instanceof \DateTimeImmutable ? $immutable->getTimestamp() : 0;

            $status_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

            if ($status_code === 304) {
                return ResponseStatus::NOT_MODIFIED;
            }

            if ($status_code !== 200) {
                $logger(sprintf('HTTP: %d', $status_code));

                continue;
            }

            try {
                $result = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $logger($e->getMessage());

                continue;
            }

            if (is_array($result)) {
                if (($headers['ratelimit-remaining'] ?? false) === '0') {
                    $logger(sprintf('Sleeping %d seconds', $headers['ratelimit-reset']));

                    time_sleep_until(time() + $headers['ratelimit-reset']);

                    $i = 0;
                }

                if (($result['status'] ?? false) === 'error') {
                    $logger($result['message']);

                    switch ($result['code']) {
                        case 401:
                            $this->token = null;

                            break;
                        case 404:
                            return ResponseStatus::NOT_FOUND;
                    }
                }
            } else {
                $logger(var_export($result, true));
            }
        } while (!is_array($result) || ($result['status'] ?? false) === 'error');

        return $result;
    }

    private function formatRequestURL(array $paths = []): string
    {
        array_unshift($paths, self::SERVER_URL);

        return sprintf(
            '%s?%s',
            implode('/', $paths),
            http_build_query(['token' => $this->token])
        );
    }

    public static function getDaysUntilExpiration(string $date): int
    {
        $tz = new \DateTimeZone('America/New_York');

        $mutable = (new \DateTime('now', $tz))->setTime(0, 0)->diff(
            \DateTime::createFromFormat('Ymd', $date, $tz)->setTime(0, 0)
        );

        return -($mutable->invert ?: -1) * $mutable->days;
    }

    public static function getExpirationType(string $date): string
    {
        $immutable = \DateTimeImmutable::createFromFormat(
            'Ymd',
            $date,
            new \DateTimeZone('America/New_York')
        )->setTime(0, 0);

        if ($immutable->format('N') === '5' && in_array($immutable->format('d'), range(15, 21))) {
            $type = 'Monthly';
        } elseif ($immutable->format('n') % 3 === 0) {
            $mutable = \DateTime::createFromImmutable($immutable)->setDate(
                (int) $immutable->format('Y'),
                (int) $immutable->format('n'),
                (int) $immutable->format('t')
            );

            $one_day = new \DateInterval('P1D');

            while ($mutable->format('N') >= 6) {
                $mutable->sub($one_day);
            }

            if ($mutable->getTimestamp() === $immutable->getTimestamp()) {
                $type = 'Quarterly';
            }
        }

        return $type ?? 'Weekly';
    }

    public function getExpirationDates(string $underlying_symbol): array|ResponseStatus
    {
        $result = $this->exec([$underlying_symbol]);

        return $this->fetch_mode && is_array($result) ? array_column($result, 'date') : $result;
    }

    public function getOption(string $option_identifier): array
    {
        $result = $this->exec([$option_identifier]);

        if ($result === ResponseStatus::NOT_FOUND) {
            $result = [];
        } elseif ($this->fetch_mode) {
            $result = array_values($result);
        }

        return $result;
    }

    public function getOptions(
        string $underlying_symbol,
        string $expiration_date,
        ?int $last_modified = null
    ): array|ResponseStatus {
        $result = $this->exec([$underlying_symbol, $expiration_date], $last_modified);

        if ($this->fetch_mode && is_array($result)) {
            $result = array_map(fn(array $option): array => array_values($option), $result);
        }

        return $result;
    }

    public function getSymbols(): array
    {
        $result = $this->exec();

        return $this->fetch_mode ? array_column($result, 'symbol') : $result;
    }

    public function getLastModified(): int
    {
        return $this->last_modified;
    }

    public function setFetchMode(int $mode): static
    {
        $this->fetch_mode = $mode;

        return $this;
    }
}
