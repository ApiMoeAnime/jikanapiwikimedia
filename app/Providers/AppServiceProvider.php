<?php

namespace App\Providers;

use App\GenreAnime;
use App\GenreManga;
use App\Http\QueryBuilder\AnimeSearchQueryBuilder;
use App\Http\QueryBuilder\CharacterSearchQueryBuilder;
use App\Http\QueryBuilder\ClubSearchQueryBuilder;
use App\Http\QueryBuilder\PeopleSearchQueryBuilder;
use App\Http\QueryBuilder\SimpleSearchQueryBuilder;
use App\Http\QueryBuilder\MangaSearchQueryBuilder;
use App\Http\QueryBuilder\TopAnimeQueryBuilder;
use App\Http\QueryBuilder\TopMangaQueryBuilder;
use App\Macros\To2dArrayWithDottedKeys;
use App\Magazine;
use App\Mixins\ScoutBuilderMixin;
use App\Producers;
use App\Services\DefaultScoutSearchService;
use App\Services\ElasticScoutSearchService;
use App\Services\ScoutSearchService;
use App\Services\TypeSenseScoutSearchService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder as ScoutBuilder;
use Typesense\LaravelTypesense\Typesense;

class AppServiceProvider extends ServiceProvider
{
    private \ReflectionClass $simpleSearchQueryBuilderClassReflection;

    /**
     * @throws \ReflectionException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function boot(): void
    {
        $this->registerMacros();
        $this->simpleSearchQueryBuilderClassReflection = new \ReflectionClass(SimpleSearchQueryBuilder::class);
    }

    /**
     * Register any application services.
     *
     * @throws \ReflectionException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(ScoutSearchService::class, function($app) {
            $scoutDriver = $this->getSearchIndexDriver($app);
            return match ($scoutDriver) {
                "typesense" => new TypeSenseScoutSearchService(),
                "Matchish\ScoutElasticSearch\Engines\ElasticSearchEngine" => new ElasticScoutSearchService(),
                default => new DefaultScoutSearchService()
            };
        });

        $queryBuilders = [
            AnimeSearchQueryBuilder::class,
            MangaSearchQueryBuilder::class,
            ClubSearchQueryBuilder::class,
            CharacterSearchQueryBuilder::class,
            PeopleSearchQueryBuilder::class,
            TopAnimeQueryBuilder::class,
            TopMangaQueryBuilder::class
        ];

        foreach($queryBuilders as $queryBuilderClass) {
            $this->app->singleton($queryBuilderClass,
                $this->getQueryBuilderFactory($queryBuilderClass)
            );
        }

        $simpleQueryBuilderAbstracts = [];
        $simpleQueryBuilders = [
            [
                "name" => "GenreAnime",
                "identifier" => "genre_anime",
                "modelClass" => GenreAnime::class
            ],
            [
                "name" => "GenreManga",
                "identifier" => "genre_manga",
                "modelClass" => GenreManga::class
            ],
            [
                "name" => "Producers",
                "identifier" => "producers",
                "modelClass" => Producers::class,
                "orderByFields" => ["mal_id", "count", "favorites", "established"]
            ],
            [
                "name" => "Magazine",
                "identifier" => "magazine",
                "modelClass" => Magazine::class
            ]
        ];

        foreach ($simpleQueryBuilders as $simpleQueryBuilder) {
            $abstractName = SimpleSearchQueryBuilder::class . $simpleQueryBuilder["name"];
            $simpleQueryBuilderAbstracts[] = $abstractName;
            $this->app->singleton($abstractName, function($app) use($simpleQueryBuilder) {
                $searchIndexesEnabled = $this->getSearchIndexesEnabledConfig($app);

                $ctorArgs = [
                    $simpleQueryBuilder["identifier"],
                    $simpleQueryBuilder["modelClass"],
                    $searchIndexesEnabled,
                    $app->make(ScoutSearchService::class)
                ];
                if (array_key_exists("orderByFields", $simpleQueryBuilder)) {
                    $ctorArgs[] = $simpleQueryBuilder["orderByFields"];
                }
                return $this->simpleSearchQueryBuilderClassReflection->newInstanceArgs($ctorArgs);
            });
        }

        $this->app->tag(array_merge($queryBuilders, $simpleQueryBuilderAbstracts), "searchQueryBuilders");

        $this->app->singleton(SearchQueryBuilderProvider::class, function($app) {
            return new SearchQueryBuilderProvider($app->tagged("searchQueryBuilders"));
        });
    }

    /**
     * @throws \ReflectionException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @return void
     */
    private function registerMacros(): void
    {
        Collection::make($this->collectionMacros())
            ->reject(fn ($class, $macro) => Collection::hasMacro($macro))
            ->each(fn ($class, $macro) => Collection::macro($macro, app($class)()));

        ScoutBuilder::mixin(new ScoutBuilderMixin());
    }

    private function collectionMacros(): array
    {
        return [
            "to2dArrayWithDottedKeys" => To2dArrayWithDottedKeys::class
        ];
    }

    private function getQueryBuilderFactory($queryBuilderClass): \Closure
    {
        return function($app) use($queryBuilderClass) {
            $searchIndexesEnabled = $this->getSearchIndexesEnabledConfig($app);
            return new $queryBuilderClass($searchIndexesEnabled, $app->make(ScoutSearchService::class));
        };
    }

    private function getSearchIndexesEnabledConfig($app): bool
    {
        return $this->getSearchIndexDriver($app) != "null";
    }

    private function getSearchIndexDriver($app): string
    {
        return $app["config"]->get("scout.driver");
    }

    public static function servicesToWarm(): array
    {
        $services = [
            ScoutSearchService::class,
            AnimeSearchQueryBuilder::class,
            MangaSearchQueryBuilder::class,
            ClubSearchQueryBuilder::class,
            CharacterSearchQueryBuilder::class,
            PeopleSearchQueryBuilder::class,
            TopAnimeQueryBuilder::class,
            TopMangaQueryBuilder::class
        ];

        if (env("SCOUT_DRIVER") === "typesense") {
            $services[] = Typesense::class;
        }

        if (env("SCOUT_DRIVER") === "Matchish\ScoutElasticSearch\Engines\ElasticSearchEngine") {
            $services[] = \Elastic\Elasticsearch\Client::class;
        }

        return $services;
    }
}
