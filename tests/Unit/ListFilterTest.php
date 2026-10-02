<?php

namespace Tests\Unit;

use App\Support\ListFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListFilterTest extends TestCase
{
    private function request(string $query): Request
    {
        return Request::create('/list?'.$query);
    }

    #[Test]
    public function brackets_indices_and_scalars_read_the_same(): void
    {
        $this->assertSame(['a', 'b'], ListFilter::values($this->request('k[]=a&k[]=b'), 'k'));
        $this->assertSame(['a', 'b'], ListFilter::values($this->request('k[0]=a&k[1]=b'), 'k'));
        $this->assertSame(['a'], ListFilter::values($this->request('k=a'), 'k'));
        $this->assertSame([], ListFilter::values($this->request(''), 'k'));
    }

    #[Test]
    public function values_are_trimmed_deduplicated_and_cleaned(): void
    {
        $this->assertSame(['a', 'b'], ListFilter::values($this->request('k[]=%20a%20&k[]=&k[]=a&k[]=b&k[x][]=c'), 'k'));
    }

    #[Test]
    public function ids_keep_positive_integers_only(): void
    {
        $this->assertSame([3, 7], ListFilter::ids($this->request('k[]=3&k[]=abc&k[]=-2&k[]=0&k[]=7&k[]=1.5'), 'k'));
        $this->assertSame([5], ListFilter::ids($this->request('k=5'), 'k'));
    }

    #[Test]
    public function more_values_than_the_cap_are_refused_not_truncated(): void
    {
        $query = fn (int $n) => implode('&', array_map(fn ($i) => "k[]={$i}", range(1, $n)));

        $this->assertCount(ListFilter::MAX_VALUES, ListFilter::ids($this->request($query(ListFilter::MAX_VALUES)), 'k'));

        $this->expectException(ValidationException::class);
        ListFilter::ids($this->request($query(ListFilter::MAX_VALUES + 1)), 'k');
    }

    #[Test]
    public function specs_keep_only_values_the_filter_applies(): void
    {
        $request = $this->request('k[]=3&k[]=none&k[]=abc&k[]=missing-4&k[]=a');

        $this->assertSame(['3'], ListFilter::get($request, 'k', ListFilter::IDS));
        $this->assertSame(['3', 'none'], ListFilter::get($request, 'k', 'ids|none'));
        $this->assertSame(['missing-4'], ListFilter::get($request, 'k', '/^missing-\d+$/'));
        $this->assertSame(['none', 'a'], ListFilter::get($request, 'k', ['a', 'none']));
        $this->assertSame(['3', 'none', 'abc', 'missing-4', 'a'], ListFilter::get($request, 'k', ListFilter::TEXT));
    }

    #[Test]
    public function the_echo_returns_applied_values_for_multi_keys_only_when_set(): void
    {
        $echo = ListFilter::echo(
            $this->request('a=1&b[]=x&b[]=y&b[]=z&search=hi&c[]=&d[]=abc'),
            ['a' => ListFilter::IDS, 'b' => ['x', 'y'], 'c' => ListFilter::TEXT, 'd' => ListFilter::IDS],
            ['search', 'per_page'],
        );

        $this->assertSame(['search' => 'hi', 'a' => ['1'], 'b' => ['x', 'y']], $echo);
    }
}
