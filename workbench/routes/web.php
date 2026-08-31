<?php

/*
|--------------------------------------------------------------------------
| The demo application's routes
|--------------------------------------------------------------------------
|
| Between them these cover the four things CONTRIBUTING.md asks you to check by hand
| after touching the runtime or the shell: a cold load that needs no component fetch
| before first paint, an A -> B -> A walk that must leave one stylesheet per live
| component, a redirect that has to travel through the SPA router, and an app that still
| boots with DevTools set to offline.
|
*/

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => pwaxRender('pages.home', ['heading' => 'Pwax'])
    // Rendered with controller data, so its payload is `no-store` unless the route says
    // otherwise. The heading is the same for everyone, and this is how a route says so —
    // which is also what puts the page in the offline cache.
    ->cacheable()
    ->title('Home')
    ->description('A demo application for working on the package.')
    ->canonical(url('/'))
    ->jsonLd([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Pwax Demo',
        'url' => url('/'),
    ]))->name('index');

Route::get('/about', fn () => pwaxRender('pages.about')
    ->title('About')
    ->description('The second page, so navigation has somewhere to go.')
    ->canonical(url('/about'))
    ->alternate('en', url('/about'))
    ->alternate('fr', url('/fr/about'))
    ->jsonLd([
        '@context' => 'https://schema.org',
        '@type' => 'AboutPage',
        'name' => 'About',
    ]))->name('about');

// A redirect from a Pwax-served route. The runtime asks for a component payload and must
// be told to navigate rather than be handed the redirect target's HTML — which is what
// `HandlePwaxRequests` translates into `X-Pwax-Location`.
Route::get('/elsewhere', fn () => redirect()->route('about'))->name('elsewhere');

/*
|--------------------------------------------------------------------------
| Data that arrives after mount
|--------------------------------------------------------------------------
|
| A page whose list comes from a `fetch` in `mounted()`. Visit it once, set DevTools to
| offline and reload: the document, the runtime, the component and the JSON are all in
| the worker's caches, so it renders and fills itself in with no network at all.
|
*/

Route::get('/items', fn () => pwaxRender('pages.items')
    ->title('Items')
    ->description('A list fetched over HTTP, and still there offline.'))
    ->name('items');

/*
|--------------------------------------------------------------------------
| A JSON document
|--------------------------------------------------------------------------
|
| An ordinary `pwaxRender()` route. The document is controller data like any other, and
| the page renders it with `<PwaxJson>` alongside markup it wrote by hand.
|
| Three things the document exercises, because all three are easy to get wrong:
| `$template` reading state, `$bindState` writing it back, and `on` dispatching an action
| the runtime provides — `navigate` here, so the button routes without a page load.
|
| `->cacheable()` is what puts it in the offline cache: the document is the same for every
| visitor, so there is nothing visitor-specific to keep off disk.
|
*/

Route::get('/sample', fn () => pwaxRender('pages.sample', [
    'doc' => [
        'root' => 'card',
        'state' => ['user' => ['name' => 'Ada'], 'open' => false],
        'elements' => [
            'card' => [
                'type' => 'Card',
                'props' => ['title' => ['$template' => 'Hello, ${/user/name}!'], 'variant' => 'raised'],
                'children' => ['field', 'reveal', 'details', 'home'],
            ],
            'field' => [
                'type' => 'Field',
                'props' => ['label' => 'Your name', 'modelValue' => ['$bindState' => '/user/name']],
            ],
            // No handler, no configuration, no PHP: the renderer owns `setState`, and
            // the panel below reads the pointer it writes.
            'reveal' => [
                'type' => 'Button',
                'props' => ['label' => 'Show the details', 'variant' => 'secondary'],
                'on' => ['press' => [
                    'action' => 'setState',
                    'params' => ['statePath' => '/open', 'value' => true],
                ]],
            ],
            'details' => [
                'type' => 'Card',
                'props' => ['title' => 'Nothing was fetched to show this.'],
                'visible' => ['$state' => '/open', 'eq' => true],
            ],
            'home' => [
                'type' => 'Button',
                'props' => ['label' => 'Back home', 'variant' => 'secondary'],
                'on' => ['press' => ['action' => 'navigate', 'params' => ['to' => '/']]],
            ],
        ],
    ],
])
    ->cacheable()
    ->title('A JSON document')
    ->description('A page that is half Blade template and half JSON document.'))
    ->name('sample');

/*
|--------------------------------------------------------------------------
| A document that tries things it is not allowed to try
|--------------------------------------------------------------------------
|
| The catalog's claim is that a document brings no markup and no script of its own. Vue
| passes any prop a component did not declare through to that component's root element,
| where `onclick` is an inline handler and `innerHTML` is parsed as HTML — so the claim
| holds only because `safeProps()` in `src/js/json/index.js` drops them first.
|
| This is that check, by hand. Every prop below is a live vector against an unfiltered
| renderer, confirmed executing in Chromium before the filter existed. The page audits its
| own DOM after mount and prints a verdict, and the console names each dropped prop.
|
*/

Route::get('/hostile', fn () => pwaxRender('pages.hostile', [
    'doc' => [
        'root' => 'card',
        'elements' => [
            'card' => [
                'type' => 'Card',
                'props' => ['title' => 'Every one of these was dropped', 'variant' => 'raised'],
                'children' => [
                    'handler', 'innocent', 'markup', 'prefixed', 'shouty',
                    'scriptUrl', 'obfuscated', 'nested', 'ok',
                ],
            ],

            // The plain one, and the one that works: `onclick` is not a Vue event prop —
            // `isOn` wants a non-lowercase character after `on` — so it takes the
            // attribute path, and `setAttribute('onclick', …)` runs on click.
            'handler' => [
                'type' => 'Text',
                'props' => ['value' => 'onclick', 'onclick' => 'window.__owned = true'],
            ],

            // The cost of the rule being blunt. `online` is nobody's attack; it is
            // dropped because the check is "anything beginning with on" rather than a
            // list of event names to keep current, and the console line is how a
            // developer finds out in a second rather than an afternoon.
            'innocent' => [
                'type' => 'Text',
                'props' => ['value' => 'online — an innocent prop, dropped too', 'online' => true],
            ],

            // `shouldSetAsProp` answers `'innerHTML' in el`, so these are set as DOM
            // properties and the string is parsed as HTML. `<img src=x onerror>` fires
            // on insertion, with no click needed.
            'markup' => [
                'type' => 'Text',
                'props' => [
                    'value' => 'innerHTML',
                    'innerHTML' => '<img src=x data-owned onerror="window.__owned = true">',
                    'textContent' => 'replaced',
                ],
            ],

            // Vue's own escape hatches, reaching the same two sinks by another spelling.
            // `^onClick` becomes a live `onclick`, because an HTML element lowercases an
            // attribute name; `.innerHTML` goes straight to the property.
            'prefixed' => [
                'type' => 'Text',
                'props' => [
                    'value' => 'prefixed',
                    '^onClick' => 'window.__owned = true',
                    '.innerHTML' => '<b data-owned>replaced</b>',
                ],
            ],

            'shouty' => [
                'type' => 'Text',
                'props' => ['value' => 'ONCLICK', 'ONCLICK' => 'window.__owned = true'],
            ],

            // The name check does nothing about a value. This one needs a component that
            // renders a prop as a URL, which `Link` does, as any link component would.
            'scriptUrl' => [
                'type' => 'Link',
                'props' => ['label' => 'A script URL', 'href' => 'javascript:window.__owned = true'],
            ],

            // The URL parser strips tab and newline from anywhere in a URL, so this is a
            // `javascript:` URL and a check for the literal prefix would pass it.
            'obfuscated' => [
                'type' => 'Link',
                'props' => ['label' => 'obfuscated', 'href' => "  java\tscri\npt:window.__owned = true"],
            ],

            // Nested one level down, which is where a list of links keeps its URLs. The
            // whole prop goes, so the nav renders empty rather than one item short —
            // a document that smuggled a URL into a list should look broken.
            'nested' => [
                'type' => 'Menu',
                'props' => ['items' => [
                    ['label' => 'A real entry', 'href' => '/about'],
                    ['label' => 'A script URL', 'href' => 'javascript:window.__owned = true'],
                ]],
            ],

            // And the control: an ordinary URL, untouched.
            'ok' => ['type' => 'Link', 'props' => ['label' => 'A real link, kept', 'href' => '/about']],
        ],
    ],
])
    ->title('What a document cannot do')
    ->description('A deliberately hostile JSON document, and the page auditing itself.'))
    ->name('hostile');

/*
|--------------------------------------------------------------------------
| Every expression and element key, on one page
|--------------------------------------------------------------------------
|
| `/sample` is the readable introduction. This is the reference: each of the eight prop
| expressions and each element key beyond `type` and `props` appears at least once, so a
| regression in any of them is visible rather than theoretical.
|
| Worth noticing while reading it: only `save` needs a handler. `setState`, `pushState`,
| `removeState` and `navigate` are supplied by the renderer or by Pwax, and the document
| below drives most of its own behaviour without a line of JavaScript.
|
*/

Route::get('/vocabulary', fn () => pwaxRender('pages.vocabulary', [
    'doc' => [
        'root' => 'page',
        'state' => [
            'note' => 'Ada Lovelace',
            'open' => false,
            'status' => null,
            'people' => [
                ['name' => 'Ada Lovelace'],
                ['name' => 'Grace Hopper'],
            ],
        ],
        'elements' => [
            // `watch` fires an action when a pointer changes, wherever the change came
            // from — here, from the field bound with `$bindState` further down.
            'page' => [
                'type' => 'Card',
                'props' => ['title' => ['$template' => 'Editing ${/note}'], 'variant' => 'raised'],
                'watch' => ['/note' => ['action' => 'save']],
                'children' => [
                    'stateRead', 'computed', 'cond', 'bound',
                    'toggle', 'secret',
                    'peopleList', 'addPerson',
                    'status', 'save', 'fail', 'stamp', 'home',
                ],
            ],

            // $state — read a pointer straight into a prop.
            'stateRead' => [
                'type' => 'Text',
                'props' => ['value' => ['$state' => '/note'], 'tone' => 'loud'],
            ],

            // $computed — call a function the page supplied.
            'computed' => [
                'type' => 'Text',
                'props' => [
                    'tone' => 'quiet',
                    'value' => [
                        '$computed' => 'initials',
                        'args' => ['name' => ['$state' => '/note']],
                    ],
                ],
            ],

            // $cond — choose between two values.
            'cond' => [
                'type' => 'Text',
                'props' => [
                    'tone' => 'quiet',
                    'value' => [
                        '$cond' => ['$state' => '/open', 'eq' => true],
                        '$then' => 'The panel is open.',
                        '$else' => 'The panel is closed.',
                    ],
                ],
            ],

            // $bindState — read and write the same pointer.
            'bound' => [
                'type' => 'Field',
                'props' => ['label' => 'Note', 'modelValue' => ['$bindState' => '/note']],
            ],

            // setState — no handler, no configuration.
            'toggle' => [
                'type' => 'Button',
                'props' => ['label' => 'Toggle the panel', 'variant' => 'secondary'],
                'on' => ['press' => [
                    'action' => 'setState',
                    'params' => ['statePath' => '/open', 'value' => true],
                ]],
            ],

            // visible — a list of conditions, which means all of them.
            'secret' => [
                'type' => 'Text',
                'props' => ['value' => 'Only shown when open, and only past two people.'],
                'visible' => [
                    ['$state' => '/open', 'eq' => true],
                    ['$state' => '/people/1', 'neq' => null],
                ],
            ],

            // repeat — renders its children once per item, with $item and $index in scope.
            // $bindItem edits the row the field is standing in.
            'peopleList' => [
                'type' => 'Card',
                'props' => ['title' => 'People'],
                'repeat' => ['statePath' => '/people', 'key' => 'name'],
                'children' => ['personIndex', 'personField', 'removePerson'],
            ],
            'personIndex' => [
                'type' => 'Text',
                'props' => ['tone' => 'quiet', 'value' => ['$index' => true]],
            ],
            'personField' => [
                'type' => 'Field',
                'props' => ['label' => 'Name', 'modelValue' => ['$bindItem' => 'name']],
            ],
            'removePerson' => [
                'type' => 'Button',
                'props' => ['label' => 'Remove the first', 'variant' => 'secondary'],
                'on' => ['press' => [
                    'action' => 'removeState',
                    'params' => ['statePath' => '/people', 'index' => 0],
                ]],
            ],

            // pushState — append. No `confirm` here on purpose: the renderer handles its
            // own actions and returns before the confirmation branch, so a `confirm` on
            // this would never ask. It goes on `save` below, which has a handler.
            'addPerson' => [
                'type' => 'Button',
                'props' => ['label' => 'Add someone'],
                'on' => ['press' => [
                    'action' => 'pushState',
                    'params' => ['statePath' => '/people', 'value' => ['name' => 'New person']],
                ]],
            ],

            // What a handler wrote back. A handler only ever receives `params` — writing
            // to state is `onSuccess`'s job, which is why the two are shown together.
            'status' => [
                'type' => 'Text',
                'props' => ['tone' => 'quiet', 'value' => ['$state' => '/status']],
                'visible' => ['$state' => '/status', 'neq' => null],
            ],

            // Two bindings on one event: one that needs a handler and one that does not.
            // `$error.message` is substituted with the thrown message if `save` rejects.
            'save' => [
                'type' => 'Button',
                'props' => ['label' => 'Save'],
                'on' => ['press' => [
                    [
                        'action' => 'save',
                        'params' => ['note' => ['$state' => '/note']],
                        'confirm' => ['title' => 'Save this note?', 'message' => 'The dialog is the renderer\'s own, and unstyled.'],
                        'onSuccess' => ['set' => ['/status' => 'Saved.']],
                        'onError' => ['set' => ['/status' => '$error.message']],
                    ],
                    ['action' => 'setState', 'params' => ['statePath' => '/open', 'value' => false]],
                ]],
            ],

            // The failing half of the pair. `$error.message` in an `onError` `set` is
            // substituted with what the handler threw.
            'fail' => [
                'type' => 'Button',
                'props' => ['label' => 'Save, but fail', 'variant' => 'secondary'],
                'on' => ['press' => [
                    'action' => 'explode',
                    'onError' => ['set' => ['/status' => '$error.message']],
                ]],
            ],

            // A component from `window`, reached by dotted path. Its event is named in
            // config because a global has no `emits` the runtime can read.
            'stamp' => [
                'type' => 'Stamp',
                'props' => ['label' => 'A component from a library'],
                'on' => ['stamped' => [
                    'action' => 'setState',
                    'params' => ['statePath' => '/status', 'value' => 'Stamped.'],
                ]],
            ],

            'home' => [
                'type' => 'Button',
                'props' => ['label' => 'Back home', 'variant' => 'secondary'],
                'on' => ['press' => ['action' => 'navigate', 'params' => ['to' => '/']]],
            ],
        ],
    ],
])
    ->cacheable()
    ->title('The document vocabulary')
    ->description('Every expression and element key json-render defines, on one page.'))
    ->name('vocabulary');

Route::get('/api/items', fn () => response()->json([
    'items' => [
        ['id' => 1, 'title' => 'First'],
        ['id' => 2, 'title' => 'Second'],
        ['id' => 3, 'title' => 'Third'],
    ],
])->header('Cache-Control', 'public, max-age=60'))->name('api.items');
