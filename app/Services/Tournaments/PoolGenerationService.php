<?php

namespace App\Services\Tournaments;

use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\TournamentStudentList;

class PoolGenerationService
{
    private Tournament $tournament;

    public function generate(int $tournamentId, ?int $listId = null): array
    {
        $tournament = Tournament::query()->findOrFail($tournamentId);
        $lists = ListTournament::query()
            ->with(['students.coach', 'templateStudentList'])
            ->where('tournament_id', $tournamentId)
            ->when($listId, fn ($query) => $query->whereKey($listId))
            ->get();

        $this->tournament = $tournament;
        $generated = 0;

        if ($tournament->tournament_type == Tournament::KATA && $tournament->tournament_type_kata == Tournament::POINT_SYSTEM) {
            if ($listId) {
                $poolsExist = $tournament->kataPools()->where('list_id', $listId)->exists();

                if ($poolsExist) {
                    KataPool::where('tournament_id', $tournamentId)
                        ->where('list_id', $listId)
                        ->delete();
                }

                $listTournament = $lists->firstWhere('id', $listId);
                $students = $listTournament?->students;

                if ($students?->isNotEmpty()) {
                    $generated += $this->generateKataPools($students, $tournamentId, $listTournament->id);
                }
            } else {
                $pools = $tournament->kataPools()->exists();
                if ($pools) {
                    KataPool::where('tournament_id', $tournamentId)->delete();
                }

                foreach ($lists as $listTournament) {
                    $students = $listTournament->students;

                    if ($students->isNotEmpty()) {
                        $generated += $this->generateKataPools($students, $tournamentId, $listTournament->id);
                    }
                }
            }

        } else {
            if ($listId) {

                // Проверяем, есть ли пули для указанного списка
                $poolsExist = $tournament->pools()->where('list_id', $listId)->exists();

                if ($poolsExist) {
                    // Удаляем только пули для этого списка
                    Pool::where('tournament_id', $tournamentId)
                        ->where('list_id', $listId)
                        ->delete();
                }
                $listTournament = $lists->firstWhere('id', $listId);
                if ($listTournament) {
                    $generated += $this->generateKumitePoolsForList($listTournament, $tournamentId);
                }

            } else {

                $pools = $tournament->pools()->exists();
                if ($pools) {
                    Pool::where('tournament_id', $tournamentId)->delete();
                }

                foreach ($lists as $listTournament) {
                    $generated += $this->generateKumitePoolsForList($listTournament, $tournamentId);
                }
            }
        }

        return [
            'generated' => $generated,
            'list_id' => $listId,
            'tournament_id' => $tournamentId,
        ];
    }

    private function generateKumitePoolsForList(ListTournament $listTournament, int $tournamentId): int
    {
        $students = $listTournament->students;
        $before = Pool::query()->where('tournament_id', $tournamentId)->where('list_id', $listTournament->id)->count();

        if ($students->count() == 2) {
            $this->generateForTwoStudents($students, $tournamentId, $listTournament->id);
        } elseif ($students->count() == 3) {
            $this->generateForThreeStudents($students, $tournamentId, $listTournament->id);
        } elseif ($students->count() == 4) {
            $this->generateForFourStudents($students, $tournamentId, $listTournament->id);
        } elseif ($students->count() >= 5 && $students->count() <= 8) {
            $this->generateForFiveToEightStudents($students, $tournamentId, $listTournament->id);
        } elseif ($students->count() >= 9 && $students->count() <= 16) {
            $this->generateForNineToSixteenStudents($students, $tournamentId, $listTournament->id);
        } elseif ($students->count() >= 17 && $students->count() <= 32) {
            $this->generateForSeventeenToThirtyTwoStudents($students, $tournamentId, $listTournament->id);
        }

        return Pool::query()->where('tournament_id', $tournamentId)->where('list_id', $listTournament->id)->count() - $before;
    }

    protected function generateKataPools($students, $tournamentId, $listTournamentId): int
    {
        $generated = 0;

        // Группируем студентов по group_id, null остаётся как отдельная группа
        $groupedByGroupId = $students->groupBy(function ($student) use ($listTournamentId) {
            $group = TournamentStudentList::where('student_id', $student->id)
                ->where('list_tournament_id', $listTournamentId)
                ->first();
            return $group->group_id ?? null;
        });

        foreach ($groupedByGroupId as $groupId => $groupStudents) {
            if ($groupId) {
                // Если group_id не пуст, создаём одну запись и сохраняем массив ID студентов
                $studentIds = $groupStudents->map(fn($student) => $student->id)->toArray();

                KataPool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'round' => 'PRELIMINARY STAGE',
                    'group_id' => $groupId,
                    'students' => $studentIds, // Сохраняем массив ID студентов в поле JSON
                ]);
                $generated++;
            } else {
                // Если group_id пуст, создаём отдельные записи для каждого студента
                foreach ($groupStudents as $student) {
                    KataPool::create([
                        'student_id' => $student->id,
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'round' => 'PRELIMINARY STAGE',
                        'group_id' => null,
                    ]);
                    $generated++;
                }
            }
        }

        return $generated;
    }

    protected
    function generateForTwoStudents($students, $tournamentId, $listTournamentId)
    {
        $this->scheduleFight($students[0], $students[1], $tournamentId, $listTournamentId, 1, 1, 'final');

    }

    protected
    function generateForThreeStudents($students, $tournamentId, $listTournamentId)
    {
        $groupedByTreners = $students->groupBy('coach_id');

        if (count($groupedByTreners) == 2) {
            $lastFightGroup = $groupedByTreners->filter(function ($group) {
                return count($group) == 2;
            })->first();
            $firstFightGroup = $groupedByTreners->filter(function ($group) {
                return count($group) == 1;
            })->first();

            $this->scheduleFight($firstFightGroup[0], $lastFightGroup[0], $tournamentId, $listTournamentId, 1, 1, 'Round Robin');
            $this->scheduleFight($firstFightGroup[0], $lastFightGroup[1], $tournamentId, $listTournamentId, 1, 2, 'Round Robin');
            $this->scheduleFight($lastFightGroup[0], $lastFightGroup[1], $tournamentId, $listTournamentId, 1, 3, 'Round Robin');
        } else {
            for ($i = 0; $i < $students->count(); $i++) {
                for ($j = $i + 1; $j < $students->count(); $j++) {
                    $this->scheduleFight($students[$i], $students[$j], $tournamentId, $listTournamentId, 1, $i + $j, 'Round Robin');
                }
            }
        }
    }

    protected
    function generateForFourStudents($students, $tournamentId, $listTournamentId)
    {
        // Разделяем студентов по тренерам (клубам)
        $groupedByTrainers = $students->groupBy('coach_id');

        if ($groupedByTrainers->count() > 1) {
            // Случай, когда есть хотя бы два разных клуба

            // Находим клуб, в котором больше одного студента
            $clubWithTwoStudents = $groupedByTrainers->first(function ($group) {
                return $group->count() == 2;
            });

            // Проверяем, есть ли два одноклубника и два других студента
            if ($clubWithTwoStudents && $groupedByTrainers->count() == 2) {
                $otherClub = $groupedByTrainers->filter(function ($group) use ($clubWithTwoStudents) {
                    return $group !== $clubWithTwoStudents;
                })->first();

                // Создаём пары так, чтобы одноклубники были разведены
                $this->scheduleFight($clubWithTwoStudents[0], $otherClub[0], $tournamentId, $listTournamentId, 1, 1, '1/2'); // Бой 1
                $this->scheduleFight($clubWithTwoStudents[1], $otherClub[1], $tournamentId, $listTournamentId, 1, 2); // Бой 2
            } else {
                // Стандартный случай (если нет двух одноклубников), просто распределяем пары
                $this->scheduleFight($students[0], $students[1], $tournamentId, $listTournamentId, 1, 1, '1/2');
                $this->scheduleFight($students[2], $students[3], $tournamentId, $listTournamentId, 1, 2, '1/2');
            }
        } else {
            // Если все из одного клуба или нет одноклубников, распределяем пары стандартно
            $this->scheduleFight($students[0], $students[1], $tournamentId, $listTournamentId, 1, 1, '1/2');
            $this->scheduleFight($students[2], $students[3], $tournamentId, $listTournamentId, 1, 2, '1/2');
        }

        // Создаём финальный бой для победителей
        $round = 2;
        Pool::create(['type' => 'final', 'tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // **Бой за третье место**
        // Проверяем, включен ли бой за третье место
        if ($this->tournament->fight_for_third_place) {
            $round = 3;
            Pool::create([
                'type' => '3rd',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null, // Заполните фактическими участниками для боя за третье место
                'opponent_id' => null, // Заполните фактическими участниками для боя за третье место
                'round' => $round,
                'position_in_round' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    protected
    function generateForFiveToEightStudents($students, $tournamentId, $listTournamentId)
    {
        $totalStudents = $students->count();
        $round = 1;
        $position = 1;

        // Группируем студентов по клубу
        $studentsByClub = $students->groupBy('coach_id')->sortByDesc(function ($group) {
            return $group->count();
        });

        // Список студентов для боев, отсортированный с учетом распределения по клубам
        $orderedStudents = collect();

        $maxGroupSize = max($studentsByClub->map->count()->toArray());

        for ($i = 0; $i < $maxGroupSize; $i++) {
            foreach ($studentsByClub as $clubStudents) {
                if (isset($clubStudents[$i])) {
                    $orderedStudents->push($clubStudents[$i]);
                }
            }
        }

        // Начинаем распределение по сетке
        if ($totalStudents === 5) {
            // Логика для 5 участников - 3 привода

            // Берем двух участников для реального боя из упорядоченного списка
            $studentsInPreliminary = $orderedStudents->take(2);
            $remainingStudents = $orderedStudents->skip(2)->values();

            // Создаем два пустых боя в первых двух позициях первого раунда
            for ($i = 0; $i < 2; $i++) {
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Реальный бой с участием двух студентов на третьей позиции первого раунда
            $this->scheduleFight(
                $studentsInPreliminary[0],
                $studentsInPreliminary[1],
                $tournamentId,
                $listTournamentId,
                $round,
                $position++
            );

            // Добавляем еще один пустой бой для завершения первого раунда
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Второй раунд: бой между студентами 3 и 4, студент 5 получает "бай"
            $round = 2;
            $position = 1;
            $this->scheduleFight(
                $remainingStudents[0], // Student 3
                $remainingStudents[1], // Student 4
                $tournamentId,
                $listTournamentId,
                $round,
                $position++,
                $type = '1/2'
            );

            // Студент 5 автоматически проходит во второй раунд
            Pool::create([
                'type' => '1/2',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null, // Student 5
                'opponent_id' => $remainingStudents[2]->id,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Третий раунд: финал с одним боем
            Pool::create([
                'type' => 'final',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => 3,
                'position_in_round' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // **Бой за третье место**
            // Проверяем, включен ли бой за третье место
            if ($this->tournament->fight_for_third_place) {
                $round = 4;
                Pool::create([
                    'type' => '3rd',
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null, // Заполните фактическими участниками для боя за третье место
                    'opponent_id' => null, // Заполните фактическими участниками для боя за третье место
                    'round' => $round,
                    'position_in_round' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

        } elseif ($totalStudents === 6) {
            // Логика для 6 участников - 2 привода

            // Выбираем участников для реальных боев из упорядоченного списка
            $studentsInPreliminary = $orderedStudents->take(4);
            $studentsBypassFirstRound = $orderedStudents->skip(4)->values();

            // Первый раунд
            // 1. Реальный бой между студентами 1 и 2
            $this->scheduleFight(
                $studentsInPreliminary[0],
                $studentsInPreliminary[1],
                $tournamentId,
                $listTournamentId,
                $round,
                $position++
            );

            // 2. Пустой бой
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // 3. Пустой бой
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // 4. Реальный бой между студентами 3 и 4
            $this->scheduleFight(
                $studentsInPreliminary[2],
                $studentsInPreliminary[3],
                $tournamentId,
                $listTournamentId,
                $round,
                $position++
            );

            // Второй раунд
            $round = 2;
            $position = 1;

            // 5. Студент 5 получает бай
            Pool::create([
                'type' => '1/2',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null, // Student 5
                'opponent_id' => $studentsBypassFirstRound[0]->id,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // 6. Студент 6 получает бай
            Pool::create([
                'type' => '1/2',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => $studentsBypassFirstRound[1]->id, // Student 6
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Третий раунд (финал) - добавляем один пустой бой
            $round = 3;
            $position = 1;

            Pool::create([
                'type' => 'final',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // **Бой за третье место**
            // Проверяем, включен ли бой за третье место
            if ($this->tournament->fight_for_third_place) {
                $round = 4;
                Pool::create([
                    'type' => '3rd',
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null, // Заполните фактическими участниками для боя за третье место
                    'opponent_id' => null, // Заполните фактическими участниками для боя за третье место
                    'round' => $round,
                    'position_in_round' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

        } elseif ($totalStudents === 7) {
            // Логика для 7 участников - 1 "бай" во втором раунде

            // Первый раунд
            // 1. Пустой бой
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // 2. Реальный бой между студентами 1 и 2 (из упорядоченного списка)
            $this->scheduleFight(
                $orderedStudents[0],
                $orderedStudents[1],
                $tournamentId,
                $listTournamentId,
                $round,
                $position++
            );

            // 3. Реальный бой между студентами 3 и 4
            $this->scheduleFight(
                $orderedStudents[2],
                $orderedStudents[3],
                $tournamentId,
                $listTournamentId,
                $round,
                $position++
            );

            // 4. Реальный бой между студентами 5 и 6
            $this->scheduleFight(
                $orderedStudents[4],
                $orderedStudents[5],
                $tournamentId,
                $listTournamentId,
                $round,
                $position++
            );

            // Второй раунд
            $round = 2;
            $position = 1;

            // 5. Студент 7 получает бай и занимает место в первом бою второго раунда
            Pool::create([
                'type' => '1/2',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => $orderedStudents[6]->id,
                'opponent_id' => null, // Студент 7
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // 6. Пустой бой для завершения второго раунда
            Pool::create([
                'type' => '1/2',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Третий раунд (финал) - добавляем один пустой бой
            $round = 3;
            $position = 1;

            Pool::create([
                'type' => 'final',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // **Бой за третье место**
            // Проверяем, включен ли бой за третье место
            if ($this->tournament->fight_for_third_place) {
                $round = 4;
                Pool::create([
                    'type' => '3rd',
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null, // Заполните фактическими участниками для боя за третье место
                    'opponent_id' => null, // Заполните фактическими участниками для боя за третье место
                    'round' => $round,
                    'position_in_round' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

        } elseif ($totalStudents === 8) {
            $numberOfMatches = $totalStudents / 2;
            for ($i = 0; $i < $totalStudents; $i += 2) {
                $this->scheduleFight($students[$i], $students[$i + 1], $tournamentId, $listTournamentId, $round, $i / 2 + 1);
            }

            $this->generateEmptyRounds($numberOfMatches / 2, $tournamentId, $listTournamentId, $round + 1, '1/2');

            // **Бой за третье место**
            // Проверяем, включен ли бой за третье место
            if ($this->tournament->fight_for_third_place) {
                $round = 4;
                Pool::create([
                    'type' => '3rd',
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null, // Заполните фактическими участниками для боя за третье место
                    'opponent_id' => null, // Заполните фактическими участниками для боя за третье место
                    'round' => $round,
                    'position_in_round' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

        } else {
            throw new \Exception("Этот метод поддерживает только генерацию сетки для 5-8 участников.");
        }
    }

    protected
    function generateForNineToSixteenStudents($students, $tournamentId, $listTournamentId)
    {
        $totalStudents = $students->count();
        $round = 1;
        $position = 1;

        if ($totalStudents < 9 || $totalStudents > 16) {
            throw new \Exception("Этот метод поддерживает только генерацию сетки для 9-16 участников.");
        }

        // Группируем студентов по клубам и создаем упорядоченный список для разведения одноклубников
        $studentsByClub = $students->groupBy('coach_id')->sortByDesc(function ($group) {
            return $group->count();
        });

        $orderedStudents = collect();
        $maxGroupSize = max($studentsByClub->map->count()->toArray());

        for ($i = 0; $i < $maxGroupSize; $i++) {
            foreach ($studentsByClub as $clubStudents) {
                if (isset($clubStudents[$i])) {
                    $orderedStudents->push($clubStudents[$i]);
                }
            }
        }

        if ($totalStudents === 9) {
            // Логика для 9 участников
            // Заполняем первый бой и оставляем остальные пустыми
            $firstRoundFighters = $orderedStudents->take(2);
            $remainingStudents = $orderedStudents->skip(2)->values();

            $this->scheduleFight($firstRoundFighters[0], $firstRoundFighters[1], $tournamentId, $listTournamentId, $round, $position++);

            for ($i = 0; $i < 7; $i++) {
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Второй раунд
            $round = 2;
            $position = 1;
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => $remainingStudents[0]->id, 'round' => $round, 'position_in_round' => $position++]);

            for ($i = 1; $i < 4; $i++) {
                $this->scheduleFight($remainingStudents[$i * 2 - 1], $remainingStudents[$i * 2], $tournamentId, $listTournamentId, $round, $position++);
            }

        } elseif ($totalStudents === 10) {
            // Логика для 10 участников (как уже реализовано)
            $firstRoundFighters = $orderedStudents->take(4);
            $remainingStudents = $orderedStudents->skip(4)->values();

            $this->scheduleFight($firstRoundFighters[0], $firstRoundFighters[1], $tournamentId, $listTournamentId, $round, $position++);

            for ($i = 0; $i < 4; $i++) {
                Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++]);
            }

            $this->scheduleFight($firstRoundFighters[2], $firstRoundFighters[3], $tournamentId, $listTournamentId, $round, $position++);

            for ($i = 0; $i < 2; $i++) {
                Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++]);
            }

            $round = 2;
            $position = 1;
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => $remainingStudents[0]->id, 'round' => $round, 'position_in_round' => $position++]);

            $this->scheduleFight($remainingStudents[1], $remainingStudents[2], $tournamentId, $listTournamentId, $round, $position++);


            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => $remainingStudents[3]->id, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++]);

            $this->scheduleFight($remainingStudents[4], $remainingStudents[5], $tournamentId, $listTournamentId, $round, $position++);

        } elseif ($totalStudents === 11) {
            // **Логика для 11 участников**
            $firstRoundFighters = $orderedStudents->take(6); // Первые 6 студентов для трех боев
            $remainingStudents = $orderedStudents->skip(6)->values(); // Оставшиеся студенты для второго раунда

            // **Первый раунд**: Заполняем позиции 2, 4 и 6, остальные пустые
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 1
            $this->scheduleFight($firstRoundFighters[0], $firstRoundFighters[1], $tournamentId, $listTournamentId, $round, $position++); // Позиция 2
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 3
            $this->scheduleFight($firstRoundFighters[2], $firstRoundFighters[3], $tournamentId, $listTournamentId, $round, $position++); // Позиция 4
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 5
            $this->scheduleFight($firstRoundFighters[4], $firstRoundFighters[5], $tournamentId, $listTournamentId, $round, $position++); // Позиция 6

            // Создаем пустые бои для позиций 7 и 8
            for ($i = 0; $i < 2; $i++) {
                Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]);
            }

            // **Второй раунд**: 4 боя
            $round = 2;
            $position = 1;

            // Первый, второй, и третий бои второго раунда с одним участником
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => $remainingStudents[0]->id, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++]);
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => $remainingStudents[1]->id, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++]);
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => $remainingStudents[2]->id, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++]);

            // Четвертый бой с двумя оставшимися участниками
            $this->scheduleFight($remainingStudents[3], $remainingStudents[4], $tournamentId, $listTournamentId, $round, $position++);
        } elseif ($totalStudents === 12) {
            // **Логика для 12 участников**
            $firstRoundFighters = $orderedStudents->take(8); // Берем первых 8 студентов для четырех боев в первом раунде
            $remainingStudents = $orderedStudents->skip(8)->values(); // Оставшиеся студенты для второго раунда

            // **Первый раунд**: Заполняем позиции 2, 4, 6 и 8
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 1
            $this->scheduleFight($firstRoundFighters[0], $firstRoundFighters[1], $tournamentId, $listTournamentId, $round, $position++); // Позиция 2
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 3
            $this->scheduleFight($firstRoundFighters[2], $firstRoundFighters[3], $tournamentId, $listTournamentId, $round, $position++); // Позиция 4
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 5
            $this->scheduleFight($firstRoundFighters[4], $firstRoundFighters[5], $tournamentId, $listTournamentId, $round, $position++); // Позиция 6
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 7
            $this->scheduleFight($firstRoundFighters[6], $firstRoundFighters[7], $tournamentId, $listTournamentId, $round, $position++); // Позиция 8

            // **Второй раунд**: 4 боя, добавляем по одному оставшемуся участнику к каждой позиции
            $round = 2;
            $position = 1;

            // Заполняем бои второго раунда, добавляя по одному оставшемуся участнику
            $this->scheduleFight($remainingStudents[0], null, $tournamentId, $listTournamentId, $round, $position++); // Позиция 9
            $this->scheduleFight($remainingStudents[1], null, $tournamentId, $listTournamentId, $round, $position++); // Позиция 10
            $this->scheduleFight($remainingStudents[2], null, $tournamentId, $listTournamentId, $round, $position++); // Позиция 11
            $this->scheduleFight($remainingStudents[3], null, $tournamentId, $listTournamentId, $round, $position++); // Позиция 12
        } elseif ($totalStudents === 13) {
            // **Логика для 13 участников**
            $firstRoundFighters = $orderedStudents->take(10); // Берем первых 10 студентов для боев в первом раунде
            $remainingStudents = $orderedStudents->skip(10)->values(); // Оставшиеся 3 студента для второго раунда

            // **Первый раунд**: Заполняем позиции 2, 3, 4, 6 и 8
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 1
            $this->scheduleFight($firstRoundFighters[0], $firstRoundFighters[1], $tournamentId, $listTournamentId, $round, $position++); // Позиция 2
            $this->scheduleFight($firstRoundFighters[2], $firstRoundFighters[3], $tournamentId, $listTournamentId, $round, $position++); // Позиция 3
            $this->scheduleFight($firstRoundFighters[4], $firstRoundFighters[5], $tournamentId, $listTournamentId, $round, $position++); // Позиция 4
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 5
            $this->scheduleFight($firstRoundFighters[6], $firstRoundFighters[7], $tournamentId, $listTournamentId, $round, $position++); // Позиция 6
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 7
            $this->scheduleFight($firstRoundFighters[8], $firstRoundFighters[9], $tournamentId, $listTournamentId, $round, $position++); // Позиция 8

            // **Второй раунд**: добавляем оставшихся участников в бои 1, 3 и 4
            $round = 2;
            $position = 1;

            // Первый бой второго раунда с одним участником
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => $remainingStudents[0]->id, // Один студент
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++ // Позиция 9
            ]);

            // Второй бой оставляем пустым
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++ // Позиция 10
            ]);

            // Третий бой второго раунда с одним участником
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => $remainingStudents[1]->id, // Один студент
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++ // Позиция 11
            ]);

            // Четвертый бой второго раунда с одним участником
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => $remainingStudents[2]->id, // Один студент
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++ // Позиция 12
            ]);
        } elseif ($totalStudents === 14) {
            // **Логика для 14 участников**
            $firstRoundFighters = $orderedStudents->take(12); // Берем первых 12 студентов для боев в первом раунде
            $remainingStudents = $orderedStudents->skip(12)->values(); // Оставшиеся 2 студента для второго раунда

            // **Первый раунд**: Заполняем позиции 2, 3, 4, 6, 7 и 8
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 1
            $this->scheduleFight($firstRoundFighters[0], $firstRoundFighters[1], $tournamentId, $listTournamentId, $round, $position++); // Позиция 2
            $this->scheduleFight($firstRoundFighters[2], $firstRoundFighters[3], $tournamentId, $listTournamentId, $round, $position++); // Позиция 3
            $this->scheduleFight($firstRoundFighters[4], $firstRoundFighters[5], $tournamentId, $listTournamentId, $round, $position++); // Позиция 4
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 5
            $this->scheduleFight($firstRoundFighters[6], $firstRoundFighters[7], $tournamentId, $listTournamentId, $round, $position++); // Позиция 6
            $this->scheduleFight($firstRoundFighters[8], $firstRoundFighters[9], $tournamentId, $listTournamentId, $round, $position++); // Позиция 7
            $this->scheduleFight($firstRoundFighters[10], $firstRoundFighters[11], $tournamentId, $listTournamentId, $round, $position++); // Позиция 8

            // **Второй раунд**: добавляем оставшихся участников
            $round = 2;
            $position = 1;

            // Первый бой второго раунда с одним участником
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => $remainingStudents[0]->id, // Один студент
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++ // Позиция 9
            ]);

            // Второй бой остается пустым
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++ // Позиция 10
            ]);

            // Третий бой второго раунда с одним участником
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => $remainingStudents[1]->id, // Один студент
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++ // Позиция 11
            ]);

            // Четвертый бой второго раунда остается пустым
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++ // Позиция 12
            ]);
        } elseif ($totalStudents === 15) {
            // **Логика для 15 участников**
            $firstRoundFighters = $orderedStudents->take(14); // Берем первых 14 студентов для боев в первом раунде
            $remainingStudent = $orderedStudents->last(); // Оставшийся студент для второго раунда

            // **Первый раунд**: Заполняем позиции 2, 3, 4, 5, 6, 7 и 8
            Pool::create(['tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]); // Позиция 1
            $this->scheduleFight($firstRoundFighters[0], $firstRoundFighters[1], $tournamentId, $listTournamentId, $round, $position++); // Позиция 2
            $this->scheduleFight($firstRoundFighters[2], $firstRoundFighters[3], $tournamentId, $listTournamentId, $round, $position++); // Позиция 3
            $this->scheduleFight($firstRoundFighters[4], $firstRoundFighters[5], $tournamentId, $listTournamentId, $round, $position++); // Позиция 4
            $this->scheduleFight($firstRoundFighters[6], $firstRoundFighters[7], $tournamentId, $listTournamentId, $round, $position++); // Позиция 5
            $this->scheduleFight($firstRoundFighters[8], $firstRoundFighters[9], $tournamentId, $listTournamentId, $round, $position++); // Позиция 6
            $this->scheduleFight($firstRoundFighters[10], $firstRoundFighters[11], $tournamentId, $listTournamentId, $round, $position++); // Позиция 7
            $this->scheduleFight($firstRoundFighters[12], $firstRoundFighters[13], $tournamentId, $listTournamentId, $round, $position++); // Позиция 8

            // **Второй раунд**: добавляем оставшегося студента в первый бой
            $round = 2;
            $position = 1;

            // Первый бой второго раунда с одним участником
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => $remainingStudent->id, // Один студент
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++ // Позиция 9
            ]);

            // Оставляем остальные бои второго раунда пустыми
            for ($i = 0; $i < 3; $i++) {

                $pul = Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

            }
        } elseif ($totalStudents === 16) {
            // **Логика для 16 участников**
            $firstRoundFighters = $orderedStudents->take(16); // Берем всех 16 студентов для боев в первом раунде

            // **Первый раунд**: Заполняем все позиции с участниками
            for ($i = 0; $i < 8; $i++) {
                $this->scheduleFight(
                    $firstRoundFighters[$i * 2],
                    $firstRoundFighters[$i * 2 + 1],
                    $tournamentId,
                    $listTournamentId,
                    $round,
                    $position++ // Позиции 1-8
                );
            }
            $round = 2;
            $position = 1;
            for ($i = 0; $i < 4; $i++) {
                Pool::create(['type' => '', 'tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

//        $round = 2;
//        $position = 1;
//        for ($i = 0; $i < 4; $i++) {
//            Pool::create(['type' => '', 'tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]);
//        }

        // **Третий раунд (3 боя)** - пустые бои
        $round = 3;
        $position = 1;
        for ($i = 0; $i < 2; $i++) {
            Pool::create(['type' => '1/2', 'tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position++, 'created_at' => now(), 'updated_at' => now()]);
        }

        // **Финальный раунд** - один бой
        $position = 1;
        $round = 4;
        Pool::create(['type' => 'final', 'tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position, 'created_at' => now(), 'updated_at' => now()]);

        // **Бой за третье место**
        // Проверяем, включен ли бой за третье место
        if ($this->tournament->fight_for_third_place) {
            $round = 5;
            Pool::create([
                'type' => '3rd',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null, // Заполните фактическими участниками для боя за третье место
                'opponent_id' => null, // Заполните фактическими участниками для боя за третье место
                'round' => $round,
                'position_in_round' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    protected
    function generateForSeventeenToThirtyTwoStudents($students, $tournamentId, $listTournamentId)
    {
        $totalStudents = $students->count();
        if ($totalStudents < 17 || $totalStudents > 32) {
            throw new \Exception("Этот метод поддерживает только генерацию сетки для 17-32 участников.");
        }

        $studentsByClub = $students->groupBy('coach_id')->sortByDesc(function ($group) {
            return $group->count();
        });

        $orderedStudents = collect();
        $maxGroupSize = max($studentsByClub->map->count()->toArray());

        for ($i = 0; $i < $maxGroupSize; $i++) {
            foreach ($studentsByClub as $clubStudents) {
                if (isset($clubStudents[$i])) {
                    $orderedStudents->push($clubStudents[$i]);
                }
            }
        }

        $round = 1;
        $position = 1;

        if ($totalStudents === 17) {
            // **Логика для 17 участников**

            // Первый раунд: только второй бой заполнен
            $firstRoundFighters = $orderedStudents->take(2);
            $remainingStudents = $orderedStudents->skip(2)->values();

            for ($i = 1; $i <= 16; $i++) {
                if ($i === 2) {
                    $this->scheduleFight($firstRoundFighters[0], $firstRoundFighters[1], $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Второй раунд: первый бой с одним студентом, остальные полные
            $round = 2;
            $position = 1;
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => $remainingStudents->shift()->id,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Оставшиеся бои второго раунда заполняются полностью
            for ($i = 1; $i < 8; $i++) {
                $student1 = $remainingStudents->shift();
                $student2 = $remainingStudents->shift();
                $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
            }
        } elseif ($totalStudents === 18) {
            // **Логика для 18 участников**

            // Первый раунд: заполняем только второй и четвёртый бои
            $firstRoundFighters = $orderedStudents->take(4);
            $remainingStudents = $orderedStudents->skip(4)->values();

            for ($i = 1; $i <= 16; $i++) {
                if ($i === 2 || $i === 4) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Второй раунд: первые два боя с одним студентом, остальные полные
            $round = 2;
            $position = 1;

            // Первый и второй бои с одним студентом
            for ($i = 1; $i <= 2; $i++) {
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => $remainingStudents->shift()->id,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Оставшиеся бои второго раунда заполняются полностью
            for ($i = 1; $i <= 6; $i++) {
                $student1 = $remainingStudents->shift();
                $student2 = $remainingStudents->shift();
                $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
            }
        } elseif ($totalStudents === 19) {
            // **Логика для 19 участников**

            // Первый раунд: заполняем только второй, четвертый и шестой бои
            $firstRoundFighters = $orderedStudents->take(6);
            $remainingStudents = $orderedStudents->skip(6)->values();

            for ($i = 1; $i <= 16; $i++) {
                if (in_array($i, [2, 4, 6])) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Второй раунд: первые три боя с одним студентом, остальные полные
            $round = 2;
            $position = 1;

            // Первые три боя с одним студентом
            for ($i = 1; $i <= 3; $i++) {
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => $remainingStudents->shift()->id,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Оставшиеся бои второго раунда заполняются полностью
            for ($i = 1; $i <= 5; $i++) {
                $student1 = $remainingStudents->shift();
                $student2 = $remainingStudents->shift();
                $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
            }
        } elseif ($totalStudents === 20) {
            // **Логика для 20 участников**

            // Первый раунд (16 боёв): заполняем только 2, 7, 10, 15 бои
            $firstRoundFighters = $orderedStudents->take(8);
            $remainingStudents = $orderedStudents->skip(8)->values();

            for ($i = 1; $i <= 16; $i++) {
                if (in_array($i, [2, 7, 10, 15], true)) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Второй раунд (8 боёв):
            // 1 бой: 1 участник (вверху)
            // 2-3 бой: по 2 участника
            // 4-5 бой: по 1 участнику
            // 6-7 бой: по 2 участника
            // 8 бой: 1 участник
            $round = 2;
            $position = 1;

            $singlePositions = [1, 4, 5, 8];
            $doublePositions = [2, 3, 6, 7];

            foreach (range(1, 8) as $pos) {
                if (in_array($pos, $doublePositions, true)) {
                    $student1 = $remainingStudents->shift();
                    $student2 = $remainingStudents->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                    continue;
                }

                if (in_array($pos, $singlePositions, true)) {
                    $student = $remainingStudents->shift();

                    // Для визуального соответствия: в нечётных боях ставим участника "сверху" (student_id),
                    // в чётных — "снизу" (opponent_id).
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => ($pos % 2 === 1) ? ($student?->id) : null,
                        'opponent_id' => ($pos % 2 === 0) ? ($student?->id) : null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    continue;
                }

                // Fallback (не должен случиться)
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } elseif ($totalStudents === 21) {
            // **Логика для 21 участника**

            // Первый раунд: заполняем только второй, четвертый, шестой, восьмой, и десятый бои
            $firstRoundFighters = $orderedStudents->take(10);
            $remainingStudents = $orderedStudents->skip(10)->values();

            for ($i = 1; $i <= 16; $i++) {
                if (in_array($i, [2, 4, 6, 8, 10])) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Второй раунд: первые пять боев с одним студентом, остальные полные
            $round = 2;
            $position = 1;

            // Первые пять боев второго раунда с одним студентом
            for ($i = 1; $i <= 5; $i++) {
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => $remainingStudents->shift()->id,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Оставшиеся бои второго раунда заполняются полностью
            for ($i = 1; $i <= 3; $i++) {
                $student1 = $remainingStudents->shift();
                $student2 = $remainingStudents->shift();
                $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
            }
        } elseif ($totalStudents === 22) {
            // **Логика для 22 участников**

            // Первый раунд: заполняем только второй, четвертый, шестой, восьмой, десятый и двенадцатый бои
            $firstRoundFighters = $orderedStudents->take(12);
            $remainingStudents = $orderedStudents->skip(12)->values();

            for ($i = 1; $i <= 16; $i++) {
                if (in_array($i, [2, 4, 6, 8, 10, 12])) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Второй раунд: первые шесть боев с одним студентом, остальные полные
            $round = 2;
            $position = 1;

            // Первые шесть боев второго раунда с одним студентом
            for ($i = 1; $i <= 6; $i++) {
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => $remainingStudents->shift()->id,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Оставшиеся два боя второго раунда заполняются полностью
            for ($i = 1; $i <= 2; $i++) {
                $student1 = $remainingStudents->shift();
                $student2 = $remainingStudents->shift();
                $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
            }
        } elseif ($totalStudents === 23) {
            // **Логика для 23 участников**

            // Первый раунд: заполняем только второй, четвертый, шестой, восьмой, десятый, двенадцатый и четырнадцатый бои
            $firstRoundFighters = $orderedStudents->take(14);
            $remainingStudents = $orderedStudents->skip(14)->values();

            for ($i = 1; $i <= 16; $i++) {
                if (in_array($i, [2, 4, 6, 8, 10, 12, 14])) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Второй раунд: первые семь боев с одним студентом, остальные полностью заполнены
            $round = 2;
            $position = 1;

            // Первые семь боев второго раунда с одним студентом
            for ($i = 1; $i <= 7; $i++) {
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => $remainingStudents->shift()->id,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Оставшийся один бой второго раунда заполняется полностью
            $student1 = $remainingStudents->shift();
            $student2 = $remainingStudents->shift();
            $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
        } elseif ($totalStudents === 24) {
            // **Логика для 24 участников**

            // Первый раунд: заполняем только второй, четвертый, шестой, восьмой, десятый, двенадцатый, четырнадцатый и шестнадцатый бои
            $firstRoundFighters = $orderedStudents->take(16);
            $remainingStudents = $orderedStudents->skip(16)->values();

            for ($i = 1; $i <= 16; $i++) {
                if (in_array($i, [2, 4, 6, 8, 10, 12, 14, 16])) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Второй раунд: все восемь боев с одним студентом
            $round = 2;
            $position = 1;

            // Все восемь боев второго раунда с одним студентом
            for ($i = 1; $i <= 8; $i++) {
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => $remainingStudents->shift()->id,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
        } elseif ($totalStudents === 25) {
            // **Логика для 25 участников**

            // Первый раунд: заполняем только второй, третий, четвертый, шестой, восьмой, десятый, двенадцатый, четырнадцатый и шестнадцатый бои
            $firstRoundFighters = $orderedStudents->take(18);
            $remainingStudents = $orderedStudents->skip(18)->values();

            for ($i = 1; $i <= 16; $i++) {
                if (in_array($i, [2, 3, 4, 6, 8, 10, 12, 14, 16])) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Второй раунд: 8 боев, заполняем первый, третий, четвертый, пятый, шестой, седьмой, и восьмой бои с одним участником в каждом; второй бой пустой
            $round = 2;
            $position = 1;

            for ($i = 1; $i <= 8; $i++) {
                if (in_array($i, [1, 3, 4, 5, 6, 7, 8])) {
                    $student = $remainingStudents->shift();
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => $student ? $student->id : null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                } elseif ($i == 2) {
                    // Второй бой остается пустым
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                } else {
                    // Заполняем бой с обоими участниками
                    $student1 = $remainingStudents->shift();
                    $student2 = $remainingStudents->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                }
            }
            // Второй раунд для 26 участников
        } elseif ($totalStudents === 26) {
            // **Первый раунд (16 боев)**: Заполняем позиции 2, 3, 4, 6, 7, 8, 10, 12, 14, и 16
            $firstRoundFighters = $orderedStudents->take(10 * 2);
            $remainingStudents = $orderedStudents->skip(10 * 2)->values();

            $round = 1;
            $position = 1;
            foreach (range(1, 16) as $pos) {
                if (in_array($pos, [2, 3, 4, 6, 7, 8, 10, 12, 14, 16])) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // **Второй раунд (8 боев)**: Заполняем позиции 1, 3, 5, 6, 7, и 8 по одному студенту, остальные пустые
            $round = 2;
            $position = 1;
            foreach (range(1, 8) as $pos) {
                if (in_array($pos, [1, 3, 5, 6, 7, 8])) {
                    $student = $remainingStudents->shift();
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => $student->id,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        } elseif ($totalStudents === 27) {
            // **Первый раунд (16 боев)**: Заполняем позиции 2, 3, 4, 6, 7, 8, 10, 11, 12, 14, и 16
            $firstRoundFighters = $orderedStudents->take(11 * 2);
            $remainingStudents = $orderedStudents->skip(11 * 2)->values();

            $round = 1;
            $position = 1;
            foreach (range(1, 16) as $pos) {
                if (in_array($pos, [2, 3, 4, 6, 7, 8, 10, 11, 12, 14, 16])) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // **Второй раунд (8 боев)**: Заполняем позиции 1, 3, 5, 7, и 8 по одному бойцу, остальные пустые
            $round = 2;
            $position = 1;
            foreach (range(1, 8) as $pos) {
                if (in_array($pos, [1, 3, 5, 7, 8])) {
                    $student = $remainingStudents->shift();
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => $student->id,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        } elseif ($totalStudents === 28) {
            // Логика для 28 участников
            $firstRoundFighters = $orderedStudents->take(24); // Первые 24 студента для заполнения боев в первом раунде
            $remainingStudents = $orderedStudents->skip(24)->values(); // Оставшиеся 4 студента для второго раунда

            // **Первый раунд**
            $round = 1;
            $position = 1;
            foreach (range(1, 16) as $pos) {
                if (in_array($pos, [2, 3, 4, 6, 7, 8, 10, 11, 12, 14, 15, 16])) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // **Второй раунд**
            $round = 2;
            $position = 1;

// Массив позиций, в которые нужно поставить по одному участнику
            $singleParticipantPositions = [1, 3, 5, 7];

            foreach (range(1, 8) as $pos) {
                if (in_array($pos, $singleParticipantPositions)) {
                    $student = $remainingStudents->shift();
                    if ($student) {
                        Pool::create([
                            'tournament_id' => $tournamentId,
                            'list_id' => $listTournamentId,
                            'student_id' => $student->id,
                            'opponent_id' => null,
                            'round' => $round,
                            'position_in_round' => $position++,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    } else {
                        // Если не осталось студентов, создаем пустой бой на этой позиции
                        Pool::create([
                            'tournament_id' => $tournamentId,
                            'list_id' => $listTournamentId,
                            'student_id' => null,
                            'opponent_id' => null,
                            'round' => $round,
                            'position_in_round' => $position++,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                } else {
                    // Если позиция не должна быть заполнена студентом, создаем пустой бой
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        } elseif ($totalStudents === 29) {
            // **Первый раунд**: Заполняем позиции 2, 3, 4, 5, 6, 7, 8, 10, 11, 12, 14, 15, 16
            $firstRoundFighters = $orderedStudents->take(26); // Берем первых 26 студентов для боев в первом раунде
            $remainingStudents = $orderedStudents->skip(26)->values(); // Оставшиеся студенты для второго раунда

            $round = 1;
            $position = 1;
            $firstRoundPositions = [2, 3, 4, 5, 6, 7, 8, 10, 11, 12, 14, 15, 16];

            foreach (range(1, 16) as $pos) {
                if (in_array($pos, $firstRoundPositions)) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    if ($student1 && $student2) {
                        $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                    }
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // **Второй раунд**: Заполняем по одному бойцу в боях 1, 5, и 7, остальные пустые
            $round = 2;
            $position = 1;
            $secondRoundSinglePositions = [1, 5, 7];

            foreach (range(1, 8) as $pos) {
                if (in_array($pos, $secondRoundSinglePositions)) {
                    $student = $remainingStudents->shift();
                    if ($student) {
                        Pool::create([
                            'tournament_id' => $tournamentId,
                            'list_id' => $listTournamentId,
                            'student_id' => $student->id,
                            'opponent_id' => null,
                            'round' => $round,
                            'position_in_round' => $position++,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        } elseif ($totalStudents === 30) {
            // **Первый раунд**: Заполняем бои 2,3,4,5,6,7,8,9,10,11,12,14,15,16
            $firstRoundPositions = [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 14, 15, 16];
            $firstRoundFighters = $orderedStudents->take(count($firstRoundPositions) * 2);
            $remainingStudents = $orderedStudents->skip(count($firstRoundPositions) * 2)->values();
            $round = 1;
            $position = 1;

            foreach (range(1, 16) as $pos) {
                if (in_array($pos, $firstRoundPositions)) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    if ($student1 && $student2) {
                        $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                    }
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // **Второй раунд**: Заполняем бои с одним участником в позициях 1 и 7, остальные пустые
            $round = 2;
            $position = 1;
            foreach (range(1, 8) as $pos) {
                if (in_array($pos, [1, 7])) {
                    $student = $remainingStudents->shift();
                    if ($student) {
                        Pool::create([
                            'tournament_id' => $tournamentId,
                            'list_id' => $listTournamentId,
                            'student_id' => $student->id,
                            'opponent_id' => null,
                            'round' => $round,
                            'position_in_round' => $position++,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        } elseif ($totalStudents === 31) {
            // **Первый раунд**: Заполняем бои 2,3,4,5,6,7,8,9,10,11,12,13,14,15,16
            $firstRoundPositions = [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];
            $firstRoundFighters = $orderedStudents->take(count($firstRoundPositions) * 2);
            $remainingStudents = $orderedStudents->skip(count($firstRoundPositions) * 2)->values();
            $round = 1;
            $position = 1;

            foreach (range(1, 16) as $pos) {
                if (in_array($pos, $firstRoundPositions)) {
                    $student1 = $firstRoundFighters->shift();
                    $student2 = $firstRoundFighters->shift();
                    if ($student1 && $student2) {
                        $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                    }
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // **Второй раунд**: Заполняем только бой 1 с одним участником, остальные пустые
            $round = 2;
            $position = 1;
            foreach (range(1, 8) as $pos) {
                if ($pos === 1) {
                    $student = $remainingStudents->shift();
                    if ($student) {
                        Pool::create([
                            'tournament_id' => $tournamentId,
                            'list_id' => $listTournamentId,
                            'student_id' => $student->id,
                            'opponent_id' => null,
                            'round' => $round,
                            'position_in_round' => $position++,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                } else {
                    Pool::create([
                        'tournament_id' => $tournamentId,
                        'list_id' => $listTournamentId,
                        'student_id' => null,
                        'opponent_id' => null,
                        'round' => $round,
                        'position_in_round' => $position++,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        } elseif ($totalStudents === 32) {
            // **Первый раунд**: Заполняем все 16 боев с участниками
            $firstRoundFighters = $orderedStudents->take(32);
            $round = 1;
            $position = 1;

            foreach (range(1, 16) as $pos) {
                $student1 = $firstRoundFighters->shift();
                $student2 = $firstRoundFighters->shift();
                if ($student1 && $student2) {
                    $this->scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round, $position++);
                }
            }

            // **Второй раунд**: Оставляем все 8 боев пустыми
            $round = 2;
            $position = 1;
            foreach (range(1, 8) as $pos) {
                Pool::create([
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position++,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }


        }

        // **Третий раунд**: Оставляем 4 пустых боя
        $round = 3;
        $position = 1;
        foreach (range(1, 4) as $pos) {
            Pool::create([
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // **Четвертый раунд**: Оставляем 2 пустых боя
        $round = 4;
        $position = 1;
        foreach (range(1, 2) as $pos) {
            Pool::create([
                'type' => '1/2',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null,
                'opponent_id' => null,
                'round' => $round,
                'position_in_round' => $position++,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // **Финальный раунд**: Оставляем один пустой бой
        $round = 5;
        $position = 1;
        Pool::create(['type' => 'final', 'tournament_id' => $tournamentId, 'list_id' => $listTournamentId, 'student_id' => null, 'opponent_id' => null, 'round' => $round, 'position_in_round' => $position, 'created_at' => now(), 'updated_at' => now()]);

        // **Бой за третье место**
        // Проверяем, включен ли бой за третье место
        if ($this->tournament->fight_for_third_place) {
            $round = 6;
            Pool::create([
                'type' => '3rd',
                'tournament_id' => $tournamentId,
                'list_id' => $listTournamentId,
                'student_id' => null, // Заполните фактическими участниками для боя за третье место
                'opponent_id' => null, // Заполните фактическими участниками для боя за третье место
                'round' => $round,
                'position_in_round' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }


    }

    protected
    function generateEmptyRounds($numberOfMatches, $tournamentId, $listTournamentId, $round, $type = null)
    {
        $position = 1;

        while ($numberOfMatches >= 1) {
            // Если это последний матч, назначаем тип "final"
            $currentType = ($numberOfMatches === 1) ? 'final' : $type;

            for ($i = 0; $i < $numberOfMatches; $i++) {
                Pool::create([
                    'type' => $currentType,
                    'tournament_id' => $tournamentId,
                    'list_id' => $listTournamentId,
                    'student_id' => null,
                    'opponent_id' => null,
                    'round' => $round,
                    'position_in_round' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $position++;
            }

            $numberOfMatches = intdiv($numberOfMatches, 2);
            $round++;
            $position = 1;
        }
    }

    protected
    function scheduleFight($student1, $student2, $tournamentId, $listTournamentId, $round = 1, $position = 1, $type = null)
    {
        Pool::create([
            'type' => $type,
            'tournament_id' => $tournamentId,
            'list_id' => $listTournamentId,
            'student_id' => $student1 ? $student1->id : null,
            'opponent_id' => $student2 ? $student2->id : null,
            'round' => $round,
            'position_in_round' => $position,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}
