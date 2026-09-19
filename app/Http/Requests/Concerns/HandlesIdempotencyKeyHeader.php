<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Shared handling of the optional `Idempotency-Key` header on control-plane
 * operations that may be retried: trim it, reject empty or oversized values,
 * and expose it to the form request's data object.
 */
trait HandlesIdempotencyKeyHeader
{
    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

        if (! is_string($key)) {
            return;
        }

        $this->headers->set(
            'Idempotency-Key',
            trim($key),
        );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasHeader('Idempotency-Key')) {
                return;
            }

            $key = $this->header('Idempotency-Key');

            if (! is_string($key)) {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'The Idempotency-Key header must be a string.',
                );

                return;
            }

            if ($key === '') {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'The Idempotency-Key header must not be empty or whitespace only.',
                );

                return;
            }

            if (mb_strlen($key) > 191) {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'The Idempotency-Key header must not be greater than 191 characters.',
                );
            }
        });
    }

    public function getIdempotencyKey(): ?string
    {
        if (! $this->hasHeader('Idempotency-Key')) {
            return null;
        }

        return $this->header('Idempotency-Key');
    }
}
