<?php

declare(strict_types=1);

namespace App\Http\Requests\Articles;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\ArticleCatalogRules;
use Illuminate\Foundation\Http\FormRequest;

final class SaveArticleCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::ArticlesManage);
    }

    public function rules(): array
    {
        return ArticleCatalogRules::category($this->route('categoria'));
    }

    public function messages(): array
    {
        return ArticleCatalogRules::messages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ArticleCatalogRules::normalize($this->all()));
    }
}
