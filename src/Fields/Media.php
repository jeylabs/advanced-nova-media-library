<?php

namespace Ebess\AdvancedNovaMediaLibrary\Fields;

use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Ramsey\Uuid\Uuid;
use Spatie\MediaLibrary\HasMedia\HasMedia;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Illuminate\Support\Facades\Validator;

class Media extends Field
{
    public $component = 'advanced-media-library-field';

    protected $setFileNameCallback;
    protected $setNameCallback;
    protected $serializeMediaCallback;
    protected $customPropertiesFields = [];

    protected $singleMediaRules = [];

    protected $defaultValidatorRules = [];

    public $meta = ['type' => 'media'];

    public function thumbnail(string $thumbnail): self
    {
        return $this->withMeta(compact('thumbnail'));
    }

    public function conversion(string $conversion): self
    {
        return $this->withMeta(compact('conversion'));
    }

    public function customPropertiesFields(array $customPropertiesFields): self
    {
        $this->customPropertiesFields = collect($customPropertiesFields);

        return $this->withMeta(compact('customPropertiesFields'));
    }

    public function serializeMediaUsing(callable $serializeMediaUsing): self
    {
        $this->serializeMediaCallback = $serializeMediaUsing;

        return $this;
    }

    public function conversionOnView(string $conversionOnView): self
    {
        return $this->withMeta(compact('conversionOnView'));
    }

    public function multiple(): self
    {
        return $this->withMeta(['multiple' => true]);
    }

    public function fullSize(): self
    {
        return $this->withMeta(['fullSize' => true]);
    }

    public function singleMediaRules($singleMediaRules): self
    {
        $this->singleMediaRules = $singleMediaRules;

        return $this;
    }

    /**
     * @param HasMedia $model
     */
    protected function fillAttributeFromRequest(NovaRequest $request, $requestAttribute, $model, $attribute)
    {
        $data = request($requestAttribute, []);

        collect($data)
            ->filter(function ($value) {
                return $value instanceof UploadedFile;
            })
            ->each(function ($media) use ($requestAttribute) {
                Validator::make([$requestAttribute => $media], [
                    $requestAttribute => array_merge($this->defaultValidatorRules, (array)$this->singleMediaRules),
                ])->validate();
            });

        Validator::make($data, $this->rules)->validate();

        $class = get_class($model);
        $class::saved(function ($model) use ($request, $data, $attribute) {
            $this->handleMedia($request, $model, $attribute, $data);

            // fill custom properties for existing media
            $this->fillCustomPropertiesFromRequest($request, $model, $attribute);
        });
    }

    protected function handleMedia(NovaRequest $request, $model, $attribute, $data)
    {
        $remainingIds = $this->removeDeletedMedia($data, $model->getMedia($attribute))->filter(function ($i) {
            return $i;
        });

        $newIds = $this->addNewMedia($request, $data, $model, $attribute);
        $this->setOrder($remainingIds->union($newIds)->sortKeys()->all());
    }

    private function setOrder($ids)
    {
        $mediaClass = config('medialibrary.media_model');
        $mediaClass::setNewOrder($ids);
    }

    private function addNewMedia(NovaRequest $request, $data, HasMedia $model, string $collection): Collection
    {
        return collect($data)
            ->filter(function ($value) {
                if (is_string($value)) {
                    return str_contains($value, 'base64');
                }
                return $value instanceof UploadedFile;
            })->map(function ($file, int $index) use ($request, $model, $collection) {

                if (is_string($file)) {
                    $filePath = $this->storeBase64File($file);
                    $file = new File($filePath);
                    $media = $model->addMedia($file);
                    Storage::delete($filePath);
                } else {
                    $media = $model->addMedia($file);
                }

                if (is_callable($this->setFileNameCallback)) {
                    $media->setFileName(
                        call_user_func($this->setFileNameCallback, $file->getClientOriginalName(), $file->getClientOriginalExtension(), $model)
                    );
                }

                if (is_callable($this->setNameCallback)) {
                    $media->setName(
                        call_user_func($this->setNameCallback, $file->getClientOriginalName(), $model)
                    );
                }

                $media = $media->toMediaCollection($collection);

                // fill custom properties for recently created media
                $this->fillMediaCustomPropertiesFromRequest($request, $media, $index, $collection);

                return $media->getKey();
            });
    }

    private function fillCustomPropertiesFromRequest(NovaRequest $request, HasMedia $model, string $collection)
    {
        $mediaItems = $model->getMedia($collection);

        collect($request->{$collection})->reject(function ($value) {
            if (is_string($value)) {
                return str_contains($value, 'base64');
            }
            return $value instanceof UploadedFile;
        })->each(function (int $id, int $index) use ($request, $mediaItems, $collection) {
            if (!$media = $mediaItems->where('id', $id)->first()) {
                return;
            }

            $this->fillMediaCustomPropertiesFromRequest($request, $media, $index, $collection);
        });
    }

    private function fillMediaCustomPropertiesFromRequest(NovaRequest $request, $media, int $index, string $collection)
    {
        foreach ($this->customPropertiesFields as $field) {
            $field->fillInto(
                $request,
                $media,
                "custom_properties->{$field->attribute}",
                "{$collection}-custom-properties.{$index}.{$field->attribute}"
            );
        }

        $media->save();
    }

    private function removeDeletedMedia($data, Collection $medias): Collection
    {
        $remainingIds = collect($data)->filter(function ($value) {
            return !$value instanceof UploadedFile;
        })->map(function ($value) {
            return (int)$value;
        });

        $medias->pluck('id')->diff($remainingIds)->each(function ($id) use ($medias) {
            /** @var Media $media */
            if ($media = $medias->where('id', $id)->first()) {
                $media->delete();
            }
        });

        return $remainingIds;
    }

    /**
     * @param HasMedia $resource
     * @param null $attribute
     */
    public function resolve($resource, $attribute = null)
    {
        $this->value = $resource->getMedia($attribute ?? $this->attribute)
            ->map(function (\Spatie\MediaLibrary\Models\Media $media) {
                $conversion = $this->meta['conversion'] ?? null;
                $urls = [
                    'default' => $media->getFullUrl($this->meta['conversion'] ?? ''),
                ];

                if ($thumbnail = $this->meta['thumbnail'] ?? null) {
                    $urls[$thumbnail] = $media->getFullUrl($thumbnail);
                }

                if ($conversionOnView = $this->meta['conversionOnView'] ?? null) {
                    $urls[$conversionOnView] = $media->getFullUrl($conversionOnView);
                }

                return array_merge($this->serializeMedia($media), ['full_urls' => $urls]);
            });
        if ($data = $this->value->first()) {
            $thumbnailUrl = $data['full_urls'][$this->meta['thumbnail'] ?? 'default'];
            $this->withMeta(compact('thumbnailUrl'));
        }
    }

    public function serializeMedia(\Spatie\MediaLibrary\Models\Media $media): array
    {
        if ($this->serializeMediaCallback) {
            return call_user_func($this->serializeMediaCallback, $media);
        }

        return $media->toArray();
    }

    /**
     * Set a filename callable callback
     *
     * @param callable $callback
     *
     * @return $this
     */
    public function setFileName($callback)
    {
        $this->setFileNameCallback = $callback;

        return $this;
    }

    /**
     * Set a name callable callback
     *
     * @param callable $callback
     *
     * @return $this
     */
    public function setName($callback)
    {
        $this->setNameCallback = $callback;

        return $this;
    }

    /**
     * Store image fron base64 content
     *
     * @param string $file
     *
     * @return string
     */
    protected function storeBase64File($file)
    {
        $content = explode(';', $file);
        $name = $content[0] ?? null;

        $extention = explode('/', $name);
        $extention = $extention[1] ?? 'png';

        $uuid = Uuid::uuid4();
        $fileName = $uuid . '.' . $extention;
        $storagePath = "tmp/$fileName";

        $fileContent = explode(',', $content[1]);
        Storage::put($storagePath, base64_decode($fileContent[1]));

        return storage_path("app/$storagePath");
    }
}
