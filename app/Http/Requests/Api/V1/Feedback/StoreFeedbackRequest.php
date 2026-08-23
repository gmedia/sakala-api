<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Feedback;

use App\Data\Feedback\SubmitFeedbackData;
use App\Enums\FeedbackCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::enum(FeedbackCategory::class)],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'project_id' => ['nullable', 'string', 'uuid', Rule::exists('projects', 'id')],
            'deployment_id' => ['nullable', 'string', 'uuid', Rule::exists('deployments', 'id')],
            'consent' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): SubmitFeedbackData
    {
        /** @var string $category */
        $category = $this->validated('category');

        /** @var string $message */
        $message = $this->validated('message');

        /** @var string|null $projectId */
        $projectId = $this->validated('project_id');

        /** @var string|null $deploymentId */
        $deploymentId = $this->validated('deployment_id');

        $consent = $this->has('consent') ? $this->boolean('consent') : true;

        return new SubmitFeedbackData(
            category: FeedbackCategory::from($category),
            message: $message,
            projectId: $projectId,
            deploymentId: $deploymentId,
            consent: $consent,
        );
    }
}
