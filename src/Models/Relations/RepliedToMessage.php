<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Models\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;

/**
 * The message another one quotes, on the same phone. A plain where() can't scope this: eager
 * loads and whereHas() build the relation on a blank model, which has no phone to compare with.
 *
 * A phone column left out of a partial select can't be compared, so that row matches on
 * message_id alone, which is globally unique.
 *
 * @extends BelongsTo<WhatsAppMessage, WhatsAppMessage>
 */
class RepliedToMessage extends BelongsTo
{
    private const PHONE = 'whatsapp_phone_id';

    public function addConstraints()
    {
        parent::addConstraints();

        $phone = $this->child->getAttributes()[self::PHONE] ?? null;

        if (static::$constraints && $phone !== null) {
            $this->query->where($this->related->qualifyColumn(self::PHONE), $phone);
        }
    }

    public function match(array $models, EloquentCollection $results, $relation)
    {
        foreach (parent::match($models, $results, $relation) as $model) {
            $quoted = $model->relationLoaded($relation) ? $model->getRelation($relation) : null;

            if ($quoted !== null && $this->onDifferentPhones($model, $quoted)) {
                $model->setRelation($relation, null);
            }
        }

        return $models;
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)->whereColumn(
            $query->getModel()->qualifyColumn(self::PHONE), '=', $this->child->qualifyColumn(self::PHONE)
        );
    }

    private function onDifferentPhones(Model $message, Model $quoted): bool
    {
        $messagePhone = $message->getAttributes()[self::PHONE] ?? null;
        $quotedPhone = $quoted->getAttributes()[self::PHONE] ?? null;

        return $messagePhone !== null && $quotedPhone !== null && $messagePhone != $quotedPhone;
    }
}
