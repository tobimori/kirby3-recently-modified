<?php

Kirby::plugin('bnomei/recently-modified', [
    'options' => [
        'query' => "site.index(true).sortBy('modified', 'desc').onlyModifiedByUser",
        'format' => 'Y/m/d H:i:s',
        'info' => function (\Kirby\Cms\Page $page) {
            return $page->modified(option('bnomei.recently-modified.format'));
        },
        'hooks' => true,
        'limit' => 7, // track only that many
        'expire' => 1, // minutes
        'cache' => true,
    ],
    'sections' => [
        'recentlymodified' => [
            'props' => [
                'headline' => function (string $headline = 'Recently Modified') {
                    return $headline;
                },
                'query' => function (?string $query = null) {
                    $query = $query ?? option('bnomei.recently-modified.query');
                    $parentId = is_a($this->model(), \Kirby\Cms\Page::class) ? $this->model()->id() : '';
                    $pages = site()->recentlyModified($query, $parentId);
                    return array_values($pages->toArray(function ($page) {
                        return [
                            'link' => $page->panelUrl(),
                            'text' => $page->title()->value(),
                            'info' => option('bnomei.recently-modified.info')($page),
                        ];
                    }));
                }
            ]
        ],
    ],
    'siteMethods' => [
        'recentlyModified' => function (string $query, string $parentId = '') {
            $user = kirby()->user();
            $cacheKey = md5($parentId . $query);
            $keys = kirby()->cache('bnomei.recently-modified')->get($cacheKey);
            if (!$keys) {
                $page = !empty($parentId) ? page($parentId) : null;
                $collection = new \Kirby\Toolkit\Query($query, [
                    'kirby' => kirby(),
                    'site' => kirby()->site(),
                    'page' => $page,
                    'pages' => kirby()->site()->pages(),
                    'user' => $user,
                ]);
                $keys = $collection->result()
                    ->limit(intval(option('bnomei.recently-modified.limit')))
                    ->toArray(fn($page) => $page->id());
                kirby()->cache('bnomei.recently-modified')
                    ->set($cacheKey, $keys, intval(option('bnomei.recently-modified.expire')));
            }
            return pages($keys ?? []);
        },

    ],
    'pageMethods' => [
        'trackModifiedByUser' => function (bool $add = true) {
            if (!kirby()->user() || option('bnomei.recently-modified.hooks') !== true) {
                return;
            }
            $cacheKey = kirby()->user()->id();

            $listKey = $this->id();
            $list = kirby()->cache('bnomei.recently-modified')->get($cacheKey, []);
            if ($add) {
                $list[$listKey] = $this->modified();
            } elseif (array_key_exists($listKey, $list)) {
                unset($list[$listKey]);
            }
            arsort($list);
            $list = array_slice($list, 0, intval(option('bnomei.recently-modified.limit')));
            file_put_contents(__DIR__ . '/' . $cacheKey . '.txt', print_r($list, true));
            kirby()->cache('bnomei.recently-modified')->set($cacheKey, $list);
        },
    ],
    'pagesMethods' => [
        'onlyModifiedByUser' => function (?\Kirby\Cms\User $user = null) {
            $user = $user ?? kirby()->user();
            $cacheKey = kirby()->user()->id();
            $list = kirby()->cache('bnomei.recently-modified')->get($cacheKey, []);
            return $this->filterBy(function ($page) use ($list) {
                return array_key_exists($page->id(), $list);
            });
        }
    ],
    'hooks' => [
        'page.create:after' => function (\Kirby\Cms\Page $page) {
            $page->trackModifiedByUser();
        },
        'page.changeSlug:after' => function (Kirby\Cms\Page $newPage, Kirby\Cms\Page $oldPage) {
            $newPage->trackModifiedByUser();
        },
        'page.changeStatus:after' => function (Kirby\Cms\Page $newPage, Kirby\Cms\Page $oldPage) {
            $newPage->trackModifiedByUser();
        },
        'page.update:after' => function (\Kirby\Cms\Page $newPage, \Kirby\Cms\Page $oldPage) {
            $newPage->trackModifiedByUser();
        },
        'page.delete:before' => function (\Kirby\Cms\Page $page, bool $force) {
            $page->trackModifiedByUser(false);
        },
    ]
]);
