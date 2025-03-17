<?php

namespace Vormkracht10\Mails\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use ReflectionException;
use Symfony\Component\Mime\Email;
use Vormkracht10\Mails\Contracts\HasAssociatedMails;
use Vormkracht10\Mails\Helpers\ModelIdentifier;

/**
 * @mixin Mailable
 */
trait AssociatesModels
{
    /**
     * Associate model(s) with this mailable
     *
     * @param  Model|array|Collection  $model
     */
    public function associateWith($model): void
    {
        if ($model instanceof Collection) {
            $model = $model->all();
        } elseif (! is_array($model)) {
            $model = Arr::wrap($model);
        }

        $this->associateMany($model);
    }

    /**
     * Associate multiple models with this mailable
     *
     * @param  array<Model&HasAssociatedMails>  $models
     */
    public function associateMany(array $models): void
    {
        $header = $this->getEncryptedAssociatedModelsHeader($models);

        $this->withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addTextHeader(
            config('mails.headers.associate'),
            $header,
        ));
    }

    /**
     * Generate an encrypted header for model associations
     *
     * @param  array<Model>  $models
     */
    protected function getEncryptedAssociatedModelsHeader(array $models): string
    {
        $identifiers = [];

        foreach ($models as $model) {
            $identifiers[] = [$model::class, $model->getKeyname(), $model->getKey()];
        }

        $header = json_encode($identifiers);

        return encrypt($header);
    }

    /**
     * Associate models for queued mailables using stored model identifiers
     */
    protected function processQueuedModelAssociations(): void
    {
        if (isset($this->viewData['associatedModelIds']) && ! empty($this->viewData['associatedModelIds'])) {
            $models = ModelIdentifier::identifiersToModels($this->viewData['associatedModelIds']);

            // Associate with the retrieved models
            if (!empty($models)) {
                $this->associateWith($models);
            }
        }
    }

    /**
     * Build the view data for the message.
     *
     * @throws ReflectionException
     */
    public function buildViewData(): array
    {
        $data = parent::buildViewData();

        // Process any queued model associations before building the message
        $this->processQueuedModelAssociations();

        return $data;
    }
}
