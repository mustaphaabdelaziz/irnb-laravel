<?php

namespace Tests\Unit;

use App\Models\Player;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The club's name format: "lastname firstname (nickname) بن father بن grandfather", بنت for a girl's father. */
class PlayerFullnameTest extends TestCase
{
    private function player(array $attributes): Player
    {
        return (new Player)->forceFill($attributes);
    }

    #[Test]
    public function a_boy_is_son_of_his_father_and_grandfather(): void
    {
        $player = $this->player(['lastname' => 'عبد العزيز', 'firstname' => 'مصطفى', 'father' => 'محمد', 'grandfather' => 'يوسف', 'gender' => 'Male']);

        $this->assertSame('عبد العزيز مصطفى بن محمد بن يوسف', $player->fullname);
    }

    #[Test]
    public function a_girl_is_daughter_of_her_father_only(): void
    {
        $player = $this->player(['lastname' => 'عبد العزيز', 'firstname' => 'سارة', 'father' => 'محمد', 'grandfather' => 'يوسف', 'gender' => 'Female']);

        $this->assertSame('عبد العزيز سارة بنت محمد بن يوسف', $player->fullname);
    }

    #[Test]
    public function the_nickname_follows_the_first_name(): void
    {
        $player = $this->player(['lastname' => 'عبد العزيز', 'firstname' => 'مصطفى', 'nickname' => 'زيزو', 'father' => 'محمد', 'grandfather' => 'يوسف']);

        $this->assertSame('عبد العزيز مصطفى (زيزو) بن محمد بن يوسف', $player->fullname);
    }

    #[Test]
    public function missing_parts_leave_no_dangling_connector(): void
    {
        $this->assertSame('عبد العزيز سارة بنت محمد', $this->player(['lastname' => 'عبد العزيز', 'firstname' => 'سارة', 'father' => 'محمد', 'gender' => 'female'])->fullname);
        $this->assertSame('عبد العزيز مصطفى', $this->player(['lastname' => 'عبد العزيز', 'firstname' => 'مصطفى', 'grandfather' => 'يوسف'])->fullname);
    }

    #[Test]
    public function fullname_is_serialized_so_the_frontend_receives_it(): void
    {
        $array = $this->player(['lastname' => 'زيدان', 'firstname' => 'يوسف', 'father' => 'أحمد', 'grandfather' => 'علي'])->toArray();

        $this->assertSame('زيدان يوسف بن أحمد بن علي', $array['fullname']);
    }
}
