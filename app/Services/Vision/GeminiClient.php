<?php

namespace App\Services\Vision;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one place that knows how to ask Google's Gemini to read an image.
 *
 * Extracted when the second reader arrived. The endpoint, the key, the timeouts and
 * the message an operator is shown when the model id has been retired are a single
 * set of facts about one supplier; having two copies of them would mean a key moved
 * in one reader and left in the other, and a retired model answered with help in
 * one place and a stack trace in the other.
 *
 * What stays out of here is what each reader asks for: its prompt and the shape it
 * wants back. This class carries the request and hands back the decoded answer.
 *
 * Reference: https://ai.google.dev/api/generate-content
 */
class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * A table of twenty rows off a phone photograph is not a fast call, and the
     * operator is sitting in front of it. Long enough to finish, short enough that
     * a stalled call gives up while they are still watching.
     */
    private const TIMEOUT_SECONDS = 45;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Ask for one JSON answer about one or more images.
     *
     * @param  array<int, ScoreSheetImage>  $images
     * @param  array<string, mixed>  $schema  the OpenAPI subset Gemini declares, with
     *                                        its type names in uppercase
     * @return array<string, mixed>  the decoded answer, shape unchecked: what a reader
     *                               asked for is the reader's own business
     *
     * @throws ScoreSheetException
     */
    public function ask(string $prompt, array $images, array $schema): array
    {
        $key = trim((string) config('services.gemini.key'));
        $model = trim((string) config('services.gemini.model'));

        if ($key === '') {
            throw ScoreSheetException::notConfigured();
        }

        if ($model === '') {
            throw ScoreSheetException::noModel();
        }

        $parts = [['text' => $prompt]];

        foreach ($images as $image) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $image->mime,
                    'data' => base64_encode($image->bytes),
                ],
            ];
        }

        try {
            $response = Http::withHeaders([
                // In the header, never the query string: a key on the URL is a key
                // in every access log between here and Google.
                'X-goog-api-key' => $key,
            ])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(sprintf(self::ENDPOINT, $model), [
                    'contents' => [['parts' => $parts]],
                    'generationConfig' => [
                        // Copying a screen is not creative work. The same image must
                        // read the same way twice.
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $schema,
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new ScoreSheetException(
                'Gemini could not be reached: ' . $e->getMessage(),
                'The screenshot reader did not answer in time. Try again, or type the figures in.',
                $e,
            );
        }

        if ($response->failed()) {
            throw $this->refusal($response, $model);
        }

        return $this->decode($response->json());
    }

    /**
     * The answer, out of the envelope it arrives in.
     *
     * @param  mixed  $body
     * @return array<string, mixed>
     *
     * @throws ScoreSheetException
     */
    private function decode($body): array
    {
        /*
         | Every text part joined, not just the first. A model that returns its
         | answer in two parts is within its rights, and reading parts.0 alone would
         | silently drop half a table.
         */
        $text = collect(data_get($body, 'candidates.0.content.parts', []))
            ->pluck('text')
            ->filter(fn ($part) => is_string($part))
            ->implode('');

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            Log::warning('Gemini answered in a shape that could not be decoded.', [
                'finish_reason' => data_get($body, 'candidates.0.finishReason'),
                'length' => mb_strlen($text),
            ]);

            throw ScoreSheetException::unusableAnswer();
        }

        return $decoded;
    }

    /**
     * Gemini refused the request, and the operator needs to know which of the two
     * settings to go and look at.
     */
    private function refusal(Response $response, string $model): ScoreSheetException
    {
        $reason = (string) ($response->json('error.message') ?: $response->body());

        // Logged rather than shown: the body echoes back the endpoint and can quote
        // part of the request.
        Log::warning('Gemini refused a read.', [
            'status' => $response->status(),
            'model' => $model,
            'body' => mb_substr($reason, 0, 1000),
        ]);

        $public = match (true) {
            $response->status() === 404 || str_contains(strtolower($reason), 'not found') => sprintf(
                'The screenshot reader does not have a model called "%s". Set GEMINI_MODEL on the server to one this key may use.',
                $model,
            ),
            in_array($response->status(), [401, 403], true) || str_contains(strtolower($reason), 'api key') =>
                'The screenshot reader refused the API key. Check GEMINI_API_KEY on the server.',
            $response->status() === 429 =>
                'The screenshot reader is over its quota for now. Wait a moment, or type the figures in.',
            default => 'The screenshot reader refused the request. Type the figures in instead.',
        };

        return new ScoreSheetException(
            sprintf('Gemini returned HTTP %d: %s', $response->status(), mb_substr($reason, 0, 300)),
            $public,
        );
    }
}
