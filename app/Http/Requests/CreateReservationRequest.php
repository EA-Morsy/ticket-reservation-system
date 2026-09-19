<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates distinct seat labels belonging to the selected event before reservation.
 */
class CreateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'seat_numbers' => ['required', 'array', 'min:1'],
            'seat_numbers.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('seats', 'number')->where(
                    fn ($query) => $query->where('event_id', $this->integer('event_id')),
                ),
            ],
        ];
    }
}
