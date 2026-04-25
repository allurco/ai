<?php

namespace Laravel\Ai\Gateway\Gemini;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use RuntimeException;

class GeminiAudioGateway implements AudioGateway
{
    use HandlesFailoverErrors;

    /**
     * Voice aliases mapping descriptive names to Gemini voice names.
     *
     * @var array<string, string>
     */
    protected const VOICE_ALIASES = [
        'default-female' => 'Kore',
        'default-male' => 'Puck',
        'female' => 'Kore',
        'male' => 'Puck',
        'female_one' => 'Kore',
        'female_two' => 'Aoede',
        'male_one' => 'Puck',
        'male_two' => 'Charon',
    ];

    /**
     * Generate audio from the given text using Gemini TTS.
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        $payloadText = $instructions ? "{$instructions}: {$text}" : $text;

        $response = $this->withErrorHandling($provider->name(), fn () => Http::withHeaders([
            'x-goog-api-key' => $provider->providerCredentials()['key'],
        ])->timeout($timeout)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
            [
                'contents' => [['parts' => [['text' => $payloadText]]]],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => $this->buildSpeechConfig($voice),
                ],
            ],
        )->throw());

        $inlineData = $response->json('candidates.0.content.parts.0.inlineData');

        if (! $inlineData || empty($inlineData['data'])) {
            throw new RuntimeException('No audio data received from Gemini API');
        }

        return new AudioResponse(
            $inlineData['data'],
            new Meta($provider->name(), $model),
            $inlineData['mimeType'] ?? 'audio/L16;rate=24000',
        );
    }

    /**
     * Build the speech configuration for Gemini TTS.
     *
     * @return array<string, mixed>
     */
    protected function buildSpeechConfig(string $voice): array
    {
        if ($this->isMultiSpeakerConfig($voice)) {
            return $this->buildMultiSpeakerConfig($voice);
        }

        return $this->buildSingleSpeakerConfig($voice);
    }

    /**
     * Check if the voice parameter contains multi-speaker configuration.
     */
    protected function isMultiSpeakerConfig(string $voice): bool
    {
        $trimmed = ltrim($voice);

        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }

    /**
     * Build single-speaker configuration.
     *
     * @return array<string, mixed>
     */
    protected function buildSingleSpeakerConfig(string $voice): array
    {
        return [
            'voiceConfig' => [
                'prebuiltVoiceConfig' => [
                    'voiceName' => $this->resolveVoiceName($voice),
                ],
            ],
        ];
    }

    /**
     * Build multi-speaker configuration from JSON.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    protected function buildMultiSpeakerConfig(string $voice): array
    {
        $speakers = json_decode($voice, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON format for multi-speaker configuration');
        }

        if (! is_array($speakers)) {
            throw new InvalidArgumentException('Multi-speaker configuration must be an array');
        }

        $speakerConfigs = [];

        foreach ($speakers as $index => $speaker) {
            $speakerConfigs[] = [
                'speaker' => $speaker['speaker'] ?? $speaker['name'] ?? 'Speaker'.($index + 1),
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [
                        'voiceName' => $this->resolveVoiceName($speaker['voice'] ?? $speaker['voiceName'] ?? 'female'),
                    ],
                ],
            ];
        }

        return [
            'multiSpeakerVoiceConfig' => [
                'speakerVoiceConfigs' => $speakerConfigs,
            ],
        ];
    }

    /**
     * Resolve a voice alias to a Gemini voice name.
     */
    protected function resolveVoiceName(string $voice): string
    {
        return self::VOICE_ALIASES[$voice] ?? $voice;
    }
}
