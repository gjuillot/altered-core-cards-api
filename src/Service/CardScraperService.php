<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CardScraperService
{
    private const API_BASE_URL = 'https://api.altered.gg';
    private const TRANSLATION_LOCALES = ['fr-fr', 'it-it', 'de-de', 'es-es'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private Filesystem $filesystem,
        #[Autowire(env: 'COMMUNITY_DATABASE')]
        private string $defaultDirectory,
    ) {}

    /**
     * Fetches a card from the Altered API in all locales, saves it to community_database,
     * and returns the assembled data array ready for CardBuilder.
     *
     * @throws \RuntimeException if the card is not found or the reference is malformed
     */
    public function scrape(string $reference, ?string $baseDirectory = null): array
    {
        $parts = explode('_', $reference);
        if (count($parts) < 6) {
            throw new \InvalidArgumentException(sprintf('Invalid reference format: %s', $reference));
        }

        [, $set, , $faction, $cardNumber] = $parts;

        $data = $this->fetchCard($reference, 'en-us');
        if ($data === null) {
            throw new \RuntimeException(sprintf('Card not found on Altered API: %s', $reference));
        }

        $data['translations'] = [];
        foreach (self::TRANSLATION_LOCALES as $locale) {
            $translation = $this->fetchCard($reference, $locale);
            if ($translation !== null) {
                $data['translations'][$locale] = $translation;
            }
        }

        $base      = rtrim($baseDirectory ?? $this->defaultDirectory, '/');
        $directory = sprintf('%s/%s/%s/%s', $base, $set, $faction, $cardNumber);
        $filePath  = sprintf('%s/%s.json', $directory, $reference);

        $this->filesystem->mkdir($directory);
        $this->filesystem->dumpFile(
            $filePath,
            json_encode($this->sortKeysRecursive($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $data;
    }

    private function fetchCard(string $reference, string $locale): ?array
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('%s/cards/%s?locale=%s', self::API_BASE_URL, $reference, $locale),
            );
            return $response->toArray();
        } catch (\Throwable) {
            return null;
        }
    }

    private function sortKeysRecursive(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortKeysRecursive($value);
            }
        }
        return $data;
    }
}
