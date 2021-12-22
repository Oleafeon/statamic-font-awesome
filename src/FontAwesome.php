<?php

namespace Aerni\FontAwesome;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class FontAwesome
{
    protected string $apiToken;
    protected string $kitToken;

    public function __construct()
    {
        $this->apiToken = config('font-awesome.api_token') ?? '';
        $this->kitToken = config('font-awesome.kit_token') ?? '';

        $this->validateConfig();
    }

    public function all(): Collection
    {
        return $this->icons()->flatten(1)->sortBy('id')->values();
    }

    public function get(string|array $style): Collection
    {
        return $this->icons()->only($style)->flatten(1)->sortBy('id')->values();
    }

    public function styles(): Collection
    {
        return $this->icons()->keys();
    }

    public function kit(string $token = null): Collection
    {
        if ($token) {
            $this->kitToken = $token;
        }

        return Cache::rememberForever("font_awesome::kit::{$this->kitToken}", function () {
            $response = Http::withToken($this->authToken())
                ->post('https://api.fontawesome.com', [
                    'query' => $this->kitQuery()
                ])->json()['data']['me']['kit'];

            return collect([
                'id' => $response['token'],
                'url' => "https://kit.fontawesome.com/{$response['token']}.js",
                'license' => $response['licenseSelected'],
                'version' => $response['version'],
            ]);
        });
    }

    protected function icons(): Collection
    {
        return Cache::rememberForever('font_awesome::icons', function () {
            $response = Http::post('https://api.fontawesome.com', [
                'query' => $this->iconsQuery()
            ])->json()['data']['release']['icons'];

            return collect($response)->flatMap(function ($icon) {
                // The styles available for the license type of the kit.
                $styles = $icon['membership'][$this->kit()->get('license')];

                return collect($styles)->map(function ($style) use ($icon) {
                    return [
                        'style' => $style,
                        'id' => "{$icon['id']}-{$style}",
                        'label' => $icon['label'] . " ($style)",
                        'class' => $this->iconClass($icon['id'], $style)
                    ];
                })->toArray();
            })->groupBy('style');
        });
    }

    protected function iconClass(string $icon, string $style): string
    {
        return Str::startsWith($this->kit()->get('version'), '5')
            ? 'fa' . substr($style, 0, 1) . ' fa-' . $icon
            : "fa-$style fa-$icon";
    }

    protected function authToken(): string
    {
        if ($token = Cache::get('font_awesome::token')) {
            return $token;
        }

        $response = Http::withToken($this->apiToken)
            ->post('https://api.fontawesome.com/token')
            ->collect();

        Cache::put('font_awesome::token', $response->get('access_token'), $response->get('expires_in'));

        return $response->get('access_token');
    }

    protected function iconsQuery(): string
    {
        return
            'query {
                release (version:' . '"' . $this->kit()->get('version') . '"' . ') {
                    icons {
                        label
                        id
                        membership {' . $this->kit()->get('license') . '}
                    }
                }
            }';
    }

    protected function kitQuery(): string
    {
        return
            'query {
                me {
                    kit (token:' . '"' . $this->kitToken . '"' . ') {
                        token
                        licenseSelected
                        version
                    }
                }
            }';
    }

    protected function validateConfig(): void
    {
        if (empty($this->apiToken)) {
            throw new \Exception('Please add your Font Awesome API Token to your .env file.');
        }

        if (empty($this->kitToken)) {
            throw new \Exception('Please add your Font Awesome Kit Token to your .env file.');
        }
    }
}