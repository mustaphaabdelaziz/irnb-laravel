<?php

namespace Tests\Unit;

use App\Support\ListFilter;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    public function the_list_is_capped(): void
    {
        $query = implode('&', array_map(fn ($i) => "k[]={$i}", range(1, 150)));

        $this->assertCount(ListFilter::MAX_VALUES, ListFilter::ids($this->request($query), 'k'));
    }

    #[Test]
    public function the_echo_returns_lists_for_multi_keys_only_when_set(): void
    {
        $echo = ListFilter::echo($this->request('a=1&b[]=x&b[]=y&search=hi&c[]='), ['a', 'b', 'c'], ['search', 'per_page']);

        $this->assertSame(['search' => 'hi', 'a' => ['1'], 'b' => ['x', 'y']], $echo);
    }
}
