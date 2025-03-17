<?php

namespace Vormkracht10\Mails\Helpers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ModelIdentifier
{
    /**
     * Convert models to serializable identifiers for queue safety
     *
     * @param  mixed  $models  Single model, collection, or array of models
     * @return array Array of model identifiers safe for serialization
     */
    public static function modelsToIdentifiers($models): array
    {
        // Convert to array if it's a collection or single model
        if ($models instanceof Collection) {
            $models = $models->all();
        } elseif ($models instanceof Model) {
            $models = [$models];
        } elseif (! is_array($models)) {
            return [];
        }

        $identifiers = [];

        foreach ($models as $model) {
            if (! $model instanceof Model) {
                continue;
            }

            $identifiers[] = [
                'class' => get_class($model),
                'key' => $model->getKeyName(),
                'id' => $model->getKey(),
            ];
        }

        return $identifiers;
    }

    /**
     * Retrieve fresh models from identifiers
     *
     * @param  array  $identifiers  Array of model identifiers
     * @return array Array of model instances
     */
    public static function identifiersToModels(array $identifiers): array
    {
        $models = [];

        foreach ($identifiers as $identifier) {
            if (! isset($identifier['class']) || ! isset($identifier['key']) || ! isset($identifier['id'])) {
                continue;
            }

            $className = $identifier['class'];
            $keyName = $identifier['key'];
            $id = $identifier['id'];

            if (! class_exists($className)) {
                continue;
            }

            // Find the model by its primary key
            $model = $className::where($keyName, $id)->first();

            if ($model) {
                $models[] = $model;
            }
        }

        return $models;
    }
}
