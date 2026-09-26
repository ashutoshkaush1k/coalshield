<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\db\ActiveQuery;

/**
 * Listing conventions (brief rule 8) in one place:
 *   ?page=2&per_page=50       pagination (per_page capped); X-Total-Count, X-Page, X-Per-Page headers
 *   ?limit=20                 legacy alias for per_page (the old API used limit)
 *   ?filter[status]=open      equality filters on declared attributes only
 *   ?sort=-created_at,name    sorting on declared attributes only
 * Unknown filter or sort attributes are a 422, never silently ignored.
 */
final class ListingQuery
{
    /**
     * @param string[] $filterable attributes allowed in filter[...]
     * @param string[] $sortable attributes allowed in sort
     * @return array<int, mixed> the page of models
     */
    public static function apply(ActiveQuery $query, array $filterable, array $sortable, string $defaultSort = 'id'): array
    {
        $request = Yii::$app->request;
        $params = Yii::$app->params;
        $table = $query->modelClass::tableName();

        $filters = $request->get('filter', []);
        if (!is_array($filters)) {
            throw ApiException::fields(['filter' => ['INVALID_VALUE']]);
        }
        foreach ($filters as $attribute => $value) {
            if (!in_array($attribute, $filterable, true)) {
                throw ApiException::fields(["filter[$attribute]" => ['NOT_FILTERABLE']]);
            }
            $query->andWhere([$table . '.' . $attribute => $value]);
        }

        $order = [];
        foreach (array_filter(explode(',', (string) $request->get('sort', $defaultSort))) as $key) {
            $desc = str_starts_with($key, '-');
            $attribute = ltrim($key, '-');
            if (!in_array($attribute, $sortable, true)) {
                throw ApiException::fields(['sort' => ['NOT_SORTABLE']]);
            }
            $order[$table . '.' . $attribute] = $desc ? SORT_DESC : SORT_ASC;
        }
        $order[$table . '.id'] ??= SORT_ASC;   // stable pages

        $perPage = (int) ($request->get('per_page') ?? $request->get('limit') ?? $params['listing.defaultPerPage']);
        $perPage = max(1, min($perPage, $params['listing.maxPerPage']));
        $page = max(1, (int) $request->get('page', 1));

        $total = (int) (clone $query)->orderBy(null)->count();
        $items = $query->orderBy($order)->offset(($page - 1) * $perPage)->limit($perPage)->all();

        $headers = Yii::$app->response->headers;
        $headers->set('X-Total-Count', (string) $total);
        $headers->set('X-Page', (string) $page);
        $headers->set('X-Per-Page', (string) $perPage);
        return $items;
    }
}
