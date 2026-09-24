<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Tests\Support;

use BuiltByBerry\LaravelSwarmFilament\Models\SwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ListSwarmRuns;
use BuiltByBerry\LaravelSwarmFilament\Resources\SwarmRunResource\Pages\ViewSwarmRun;
use BuiltByBerry\LaravelSwarmFilament\Widgets\SwarmRunStatsOverview;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use ReflectionMethod;
use ReflectionProperty;

final class UsageRenderHarness
{
    public static function column(SwarmRun $record): string
    {
        $host = new ListSwarmRuns;
        $table = Table::make($host);
        (new ReflectionProperty($host, 'table'))->setValue($host, $table);
        SwarmRunResource::table($table);

        return $table->getColumn('tokens')->record($record)->toHtml();
    }

    public static function detail(SwarmRun $record): string
    {
        $page = new ViewSwarmRun;
        $page->record = $record;
        $schema = $page->infolist(Schema::make($page));

        return $schema->getComponents()[0]->toHtml();
    }

    public static function stat(): string
    {
        $widget = new SwarmRunStatsOverview;
        $stats = (new ReflectionMethod($widget, 'getStats'))->invoke($widget);

        return $stats[3]->container(Schema::make($widget))->toHtml();
    }

    public static function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    public static function cases(): array
    {
        $cases = [
            'legacy' => [['prompt_tokens' => 1200, 'completion_tokens' => 34], '1,234', 1234],
            'native' => [['input_tokens' => 1200, 'output_tokens' => 34], '1,234', 1234],
            'legacy zero' => [['prompt_tokens' => 0, 'completion_tokens' => 0], '0', 0],
            'native zero' => [['input_tokens' => 0, 'output_tokens' => 0], '0', 0],
            'real empty report' => [[], 'Unavailable', null],
            'unclassified' => [['reasoning_tokens' => 3], 'Unavailable', null],
            'null report' => [null, 'Unavailable', null],
            'non array' => ['12', 'Unavailable', null],
            'all null sentinel' => [[
                'input_tokens' => null, 'output_tokens' => null,
                'prompt_tokens' => null, 'completion_tokens' => null,
                'cache_read_input_tokens' => null, 'cache_write_input_tokens' => null, 'reasoning_tokens' => null,
            ], 'Mixed or unavailable', null],
            'both generations' => [['input_tokens' => 10, 'output_tokens' => 2, 'prompt_tokens' => 5, 'completion_tokens' => 1], 'Mixed or unavailable', null],
            'native subsets excluded' => [['input_tokens' => 5, 'output_tokens' => 2, 'cache_read_input_tokens' => 99, 'cache_write_input_tokens' => 4, 'reasoning_tokens' => 50], '7', 7],
            'legacy subsets excluded' => [['prompt_tokens' => 5, 'completion_tokens' => 2, 'cache_read_input_tokens' => 99, 'cache_write_input_tokens' => 4, 'reasoning_tokens' => 50], '7', 7],
        ];
        foreach ([['input_tokens', 'output_tokens'], ['prompt_tokens', 'completion_tokens']] as [$input, $output]) {
            $cases[$input.' missing'] = [[$input => 9], 'Unavailable', null];
            foreach ([null, '3', true, -1, 1.5] as $index => $bad) {
                $cases[$input.' malformed '.$index] = [[$input => $bad, $output => 9], 'Unavailable', null];
                $cases[$output.' malformed '.$index] = [[$input => 9, $output => $bad], 'Unavailable', null];
            }
        }

        return $cases;
    }
}
