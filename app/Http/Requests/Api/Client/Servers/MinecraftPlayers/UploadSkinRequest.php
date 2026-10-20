<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers;

class UploadSkinRequest extends SkinRequest
{
    public function validationData(): array
    {
        return array_replace($this->all(), $this->allFiles(), ['player' => $this->route('player')]);
    }

    public function rules(): array
    {
        return parent::rules() + [
            'skin' => ['required', 'file', 'mimetypes:image/png', 'max:64'],
            'variant' => ['required', 'in:classic,slim'],
        ];
    }
}
