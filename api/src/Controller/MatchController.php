<?php

namespace App\Controller;

use App\Entity\Game;
use App\Entity\GameMode;
use App\Entity\Player;
use App\Entity\Scores;
use App\Entity\Tournament;
use App\Entity\TournamentGroup;
use App\Repository\GameRepository;
use App\Repository\ScoresRepository;
use App\Repository\TournamentRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Client;

class MatchController extends BaseController
{
    const MINIMAL_END_SCORE = 11;
    const END_SCORE_DIFFERENCE = 2;

    public function getMatch($id)
    {
        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);
        $data = $gameRepository->loadById($id);

        if (!$data) {
            throw $this->createNotFoundException(
                'No data'
            );
        }

        return $this->sendJsonResponse($data);
    }

    public function getMatchTimeline($id)
    {
        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);
        $data = $gameRepository->loadTimelineById($id);

        if (!$data) {
            throw $this->createNotFoundException(
                'No data'
            );
        }

        return $this->sendJsonResponse($data);
    }

    /**
     * @param $id
     * @return Response
     */
    public function setServer($id)
    {
        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);
        /** @var Game $match */
        $match = $gameRepository->updateServer($id);

        if (!$match) {
            throw $this->createNotFoundException(
                'No data'
            );
        }

        return $this->sendJsonResponse($match);
    }

    /**
     * @param $matchId
     * @return Response
     * @throws \Exception
     */
    public function finishSet($matchId)
    {
        $em = $this->getDoctrine()->getManager();

        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);
        /** @var ScoresRepository $scoreRepository */
        $scoreRepository = $this->getDoctrine()->getRepository(Scores::class);

        /** @var Game $match */
        $match = $gameRepository->find($matchId);
        $currentSet = $match->getCurrentSet();

        # Update current set scores by summarizing single points
        $scoreId = $scoreRepository->getScoreIdByMatchIdAndSetNumber($matchId, $currentSet);
        $gameRepository->updateScores($scoreId);

        list($currentHomeScore, $currentAwayScore) = $match->getSetScores();

        # Update match score !
        $match->setHomeScore($currentHomeScore);
        $match->setAwayScore($currentAwayScore);

        $requiredWins = $match->getGameMode()->getWinsRequired();
        $maxSets = $match->getGameMode()->getMaxSets();

        // Update set score in Scores
        if ($currentHomeScore == $requiredWins) {
            $match->setWinnerId($match->getHomePlayer()->getId());
            $match->setCurrentSet(0);
            $match->setIsFinished(1);
        } elseif ($currentAwayScore == $requiredWins) {
            $match->setWinnerId($match->getAwayPlayer()->getId());
            $match->setCurrentSet(0);
            $match->setIsFinished(1);
        } elseif ($currentAwayScore + $currentHomeScore == $maxSets) {
            $match->setWinnerId(0);
            $match->setCurrentSet(0);
            $match->setIsFinished(1);
        }

        /*
         * If match is finished, save it, load by id
         * send slack message if configured
         *
         * Additionally, check if this is playoffs game
         * and if so, update all consequent matches with winner and loser id
         * consequent matches have the same tournament id and play_order set
         *
         */
        if ($match->getIsFinished() == 1) {
            $datePlayed = new \DateTime(null, new \DateTimeZone('Europe/Berlin'));
            $datePlayed->format('Y-m-d H:i:s');
            $match->setDatePlayed($datePlayed);

            $em->persist($match);
            $em->flush();

            # ELO recalculation
            $this->recalculateElo();

            $data = $gameRepository->loadById($matchId);

            $gameRepository->updatePlayoffs($matchId);

            $message = "> *" . $match->getTournamentGroup()->getName() . "* match finished\n";
            $message .= "> " . $data['homeSlackName'] . ' - ' . $data['awaySlackName'] . ' ' . $data['prettyScore'] . "\n";
            if ($data['pts']) {
                $message .= "> <" . $this->guiUrl . "/#/match/" . $matchId . "/summary|Summary>";
            }
            $payload = [
                'text' => $message,
                'method' => 'post',
                'contentType' => 'application/json',
                'muteHttpExceptions' => true,
                'link_names' => 1,
                'username' => 'tabletennisbot',
                'icon_emoji' => ':table_tennis_paddle_and_ball:'
            ];

            $this->post2Slack($payload);

            return $this->sendJsonResponse($data);
        } else {
            $nextSetNumber = $currentSet + 1;

            # Add next set (Score)
            $score = new Scores();
            $score->setGame($match);
            $score->setSetNumber($nextSetNumber);
            $score->setHomePoints(0);
            $score->setAwayPoints(0);
            $em->persist($score);
            $em->flush();

            $match->setCurrentSet($nextSetNumber);
            $em->persist($match);
            $em->flush();
        }

        if (!$match) {
            throw $this->createNotFoundException(
                'No data'
            );
        }

        $data = $gameRepository->loadById($matchId);

        if (!$data) {
            throw $this->createNotFoundException(
                'No data'
            );
        }

        return $this->sendJsonResponse($data);
    }

    /**
     * @param $matchId
     * @return Response
     * @throws \Exception
     */
    public function startMessage($matchId)
    {
        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);

        $data = $gameRepository->loadById($matchId);

        $groupName = $data['groupName'];
        $matchName = $data['matchName'];
        $modeName = $data['modeName'];
        $homePlayer = $data['homePlayerDisplayName'];
        $awayPlayer = $data['awayPlayerDisplayName'];
        $nextHomePlayer = $data['nextMatchHomePlayer'];
        $nextAwayPlayer = $data['nextMatchAwayPlayer'];

        $homeSlackName = $data['homeSlackName'];
        $awaySlackName = $data['awaySlackName'];

        $message = "";

        //if ($data['matchName'] == "Grand final") {
        //    $message .= ":trophy: ";
        //} else {
        $message .= ":table_tennis_paddle_and_ball: ";
        //}

        $message .= " Playoffs match is about to start (" . $groupName . ", " . $matchName . ", " . $modeName . ") ";
        $message .= "<" . $this->guiUrl . "/#/playoffs/ladders|ladder here>";
        $message .= "\n*" . $homeSlackName . "* vs *" . $awaySlackName . "*\n";
        if ($data['nextMatchId']) {
            $message .= "next: *" . $nextHomePlayer . "* vs *" . $nextAwayPlayer . "*\n";
        }

        $payload = [
            'text' => $message,
            'method' => 'post',
            'contentType' => 'application/json',
            'muteHttpExceptions' => true,
            'link_names' => 1,
            'username' => 'tabletennisbot',
            'icon_emoji' => ':table_tennis_paddle_and_ball:'
        ];

        $this->post2Slack($payload);

        return $this->sendJsonResponse($data);
    }


    /**
     * @param Request $request
     * @return JsonResponse
     * @throws \Exception
     */
    public function addMatch(Request $request)
    {
        $data = json_decode($request->getContent(), true);

        $tournamentId = $data['tournament'];
        $modeId = $data['mode'];
        $homePlayerId = $data['homePlayer'];
        $awayPlayerId = $data['awayPlayer'];

        $dateTime = $data['datetime'];
        $timestamp = strtotime($dateTime);
        $datetimeFormat = 'Y-m-d H:i:s';

        $date = new \DateTime();
        $date->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $date->setTimestamp($timestamp);
        $date->format($datetimeFormat);

        if (empty($dateTime)) {
            return new JsonResponse([
                'status' => 'error',
                'errorText' => 'Choose date and time'
            ],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        if (empty($tournamentId) || empty($modeId)) {
            return new JsonResponse([
                'status' => 'error',
                'errorText' => 'Choose tournament and match mode'
            ],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        if (empty($homePlayerId) || empty($awayPlayerId)) {
            return new JsonResponse([
                'status' => 'error',
                'errorText' => 'Choose players'
            ],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        if ($homePlayerId == $awayPlayerId) {
            return new JsonResponse([
                'status' => 'error',
                'errorText' => 'Playing with oneself, huh?'
            ],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        /*
         * Check player groups
         * Get the tournament groups
         */

        /** @var Tournament $tournament */
        $tournament = $this->getDoctrine()->getRepository(Tournament::class)->find($tournamentId);

        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);
        $groupId = $gameRepository->validateTournamentGroupPlayer(
            $tournament->getId(),
            $homePlayerId,
            $awayPlayerId
        );

        if (!$groupId) {
            return new JsonResponse([
                'status' => 'error',
                'errorText' => 'Those players are not in the same group'
            ],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        # All good
        $doctrine = $this->getDoctrine();

        /** @var GameMode $gameMode */
        $gameMode = $doctrine->getRepository(GameMode::class)->find($modeId);
        /** @var Player $homePlayer */
        $homePlayer = $doctrine->getRepository(Player::class)->find($homePlayerId);
        /** @var Player $awayPlayer */
        $awayPlayer = $doctrine->getRepository(Player::class)->find($awayPlayerId);
        /** @var TournamentGroup $group */
        $group = $doctrine->getRepository(TournamentGroup::class)->find($groupId);

        $em = $doctrine->getManager();

        $game = new Game();
        $game->setGameMode($gameMode);
        $game->setHomePlayer($homePlayer);
        $game->setAwayPlayer($awayPlayer);
        $game->setTournament($tournament);
        $game->setIsFinished(0);
        $game->setIsAbandoned(0);
        $game->setIsWalkover(0);
        $game->setCurrentSet(0);
        $game->setTournamentGroup($group);
        $game->setWinnerId(0);
        $game->setHomeScore(0);
        $game->setAwayScore(0);
        $game->setDateOfMatch($date);

        $em->persist($game);
        $em->flush();

        return new JsonResponse([
            'status' => 'done',
            'errorText' => 'Match added'
        ],
            JsonResponse::HTTP_OK
        );
    }

    public function post2Slack($data)
    {
        if ($this->slackKey) {
            $data_string = json_encode($data);

            $ch = curl_init($this->slackKey);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data_string))
            );

            return curl_exec($ch);
        }
    }

    public function saveMatch(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $data = json_decode($request->getContent(), true);

        $matchId = $data['matchId'];

        $homeSet[1] = (int)$data['h1'];
        $homeSet[2] = (int)$data['h2'];
        $homeSet[3] = (int)$data['h3'];
        $homeSet[4] = (int)$data['h4'];

        $awaySet[1] = (int)$data['a1'];
        $awaySet[2] = (int)$data['a2'];
        $awaySet[3] = (int)$data['a3'];
        $awaySet[4] = (int)$data['a4'];

        if (count($homeSet) < 3 || count($awaySet) < 3) {
            return new JsonResponse([
                'status' => 0,
                'errorText' => 0,
                'matchId' => $matchId,
                'message' => null
            ],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        # Reset match values
        $homeSetScore = 0;
        $awaySetScore = 0;
        $winnerId = 0;

        /** @var Game $match */
        $match = $this
            ->getDoctrine()
            ->getRepository(Game::class)
            ->find($matchId);

        # Get required wins value
        $requiredWins = $match->getGameMode()->getWinsRequired();

        # Meh. Remove old scores
        $scores = $match->getScores();
        foreach ($scores as $score) {
            $em->remove($score);
            $em->flush();
        }

        $textSetsScore = [];

        # Insert new scores
        for ($i = 1; $i <= 4; $i++) {
            # Id current set does not have valid value, stop here
            if (!$homeSet[$i] && !$awaySet[$i]) {
                break;
            }

            $newScore = new Scores();
            $newScore->setGame($match);
            $newScore->setSetNumber($i);
            $newScore->setHomePoints($homeSet[$i]);
            $newScore->setAwayPoints($awaySet[$i]);
            $em->persist($newScore);
            $em->flush();

            $match->addScore($newScore);

            # check if this set is finished, if it is, increase score
            if ($homeSet[$i] >= self::MINIMAL_END_SCORE &&
                $homeSet[$i] >= ($awaySet[$i] + self::END_SCORE_DIFFERENCE)
            ) {
                $homeSetScore++;
            } elseif ($awaySet[$i] >= self::MINIMAL_END_SCORE &&
                $awaySet[$i] >= ($homeSet[$i] + self::END_SCORE_DIFFERENCE)
            ) {
                $awaySetScore++;
            }

            $textSetsScore[$i] = $homeSet[$i] . '-' . $awaySet[$i];
        }

        $finished = 0;
        if ($homeSetScore == $requiredWins) {
            $winnerId = $match->getHomePlayer()->getId();
            $finished = 1;
        } elseif ($awaySetScore == $requiredWins) {
            $winnerId = $match->getAwayPlayer()->getId();
            $finished = 1;
        }

        # Match can be finished with a draw
        if ($homeSetScore + $awaySetScore == $match->getGameMode()->getMaxSets()) {
            $finished = 1;
        }

        $datePlayed = new \DateTime(null, new \DateTimeZone('Europe/Berlin'));
        $datePlayed->format('Y-m-d H:i:s');

        $match->setHomeScore($homeSetScore);
        $match->setAwayScore($awaySetScore);
        $match->setWinnerId($winnerId);
        $match->setIsFinished($finished);
        $match->setDatePlayed($datePlayed);

        $em->persist($match);
        $em->flush();
        $this->recalculateElo();

        if ($data['post2Channel'] && true === $data['post2Channel']) {
            $textSetsScore = join(', ', $textSetsScore);
            $message = "> *" . $match->getTournamentGroup()->getName() . "* match finished\n";
            $message .= "> " .
                $match->getHomePlayer()->getSlackName() . ' - ' . $match->getAwayPlayer()->getSlackName() .
                ' ' .
                $match->getHomeScore() . ' - ' . $match->getAwayScore() .
                ' (' . $textSetsScore . ')';

            $payload = [
                'text' => $message,
                'method' => 'post',
                'contentType' => 'application/json',
                'muteHttpExceptions' => true,
                'link_names' => 1,
                'username' => 'tabletennisbot',
                'icon_emoji' => ':table_tennis_paddle_and_ball:'
            ];

            $this->post2Slack($payload);
        } else {
            $message = null;
        }

        return new JsonResponse([
            'status' => 0,
            'errorText' => $requiredWins,
            'matchId' => $matchId,
            'message' => $message
        ],
            JsonResponse::HTTP_OK
        );
    }

    public function walkover($matchId, $playerId)
    {
        $em = $this->getDoctrine()->getManager();

        /** @var Game $match */
        $match = $this
            ->getDoctrine()
            ->getRepository(Game::class)
            ->find($matchId);


        # Meh. Remove old scores
        $scores = $match->getScores();
        foreach ($scores as $score) {
            $em->remove($score);
            $em->flush();
        }

        $homePlayer = $match->getHomePlayer();
        $awayPlayer = $match->getAwayPlayer();

        # Get required wins value
        $requiredWins = $match->getGameMode()->getWinsRequired();

        $datePlayed = new \DateTime(null, new \DateTimeZone('Europe/Berlin'));
        $datePlayed->format('Y-m-d H:i:s');

        if ($playerId == $homePlayer->getId()) {
            $match->setWinnerId($playerId);
            $match->setAwayScore(0);
            $match->setHomeScore($requiredWins);


            for ($i = 1; $i <= $requiredWins; $i++) {
                $newScore = new Scores();
                $newScore->setGame($match);
                $newScore->setSetNumber($i);
                $newScore->setHomePoints(11);
                $newScore->setAwayPoints(0);
                $em->persist($newScore);
                $em->flush();

                $match->addScore($newScore);
            }
        } else {
            $match->setWinnerId($awayPlayer->getId());
            $match->setAwayScore($requiredWins);
            $match->setHomeScore(0);

            for ($i = 1; $i <= $requiredWins; $i++) {
                $newScore = new Scores();
                $newScore->setGame($match);
                $newScore->setSetNumber($i);
                $newScore->setHomePoints(0);
                $newScore->setAwayPoints(11);
                $em->persist($newScore);
                $em->flush();

                $match->addScore($newScore);
            }
        }

        $match->setCurrentSet(0);
        $match->setIsFinished(true);
        $match->setIsWalkover(true);
        $match->setDatePlayed($datePlayed);

        $message =
            $match->getHomePlayer()->getSlackName() . ' - ' . $match->getAwayPlayer()->getSlackName() .
            ' ' .
            $match->getHomeScore() . ' - ' . $match->getAwayScore() .
            ' (walkover)';
        $payload = [
            'text' => $message,
            'method' => 'post',
            'contentType' => 'application/json',
            'muteHttpExceptions' => true,
            'link_names' => 1,
            'username' => 'tabletennisbot',
            'icon_emoji' => ':table_tennis_paddle_and_ball:'
        ];
        $this->post2Slack($payload);

        $em->persist($match);
        $em->flush();

        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);
        $gameRepository->updatePlayoffs($matchId);
        $data = $gameRepository->loadById($matchId);

        if (!$data) {
            throw $this->createNotFoundException(
                'No data'
            );
        }

        return $this->sendJsonResponse($data);
    }

    public function recalculateElo()
    {
        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);
        $data = $gameRepository->loadAllOrdered();

        if (!$data) {
            throw $this->createNotFoundException(
                'No data'
            );
        }

        $playerCache = [];
        $i = 0;
        foreach ($data as $game) {
            $winner = null;
            $loser = null;

            $winParam = 1;
            $loseParam = 0;

            $gameId = $game['id'];
            $homePlayerId = $game['home_player_id'];
            $awayPlayerId = $game['away_player_id'];
            $homeScore = $game['home_score'];
            $awayScore = $game['away_score'];
            $winnerId = $game['winner_id'];

            if (!array_key_exists($homePlayerId, $playerCache)) {
                $playerCache[$homePlayerId] = [
                    'elo' => 1500,
                    'gamesPlayed' => 1
                ];
            }

            if (!array_key_exists($awayPlayerId, $playerCache)) {
                $playerCache[$awayPlayerId] = [
                    'elo' => 1500,
                    'gamesPlayed' => 1
                ];
            }

            $oldHomeElo = $playerCache[$homePlayerId]['elo'];
            $oldAwayElo = $playerCache[$awayPlayerId]['elo'];

            if ($winnerId == $homePlayerId) {
                $winner = $playerCache[$homePlayerId];
                $loser = $playerCache[$awayPlayerId];
            } elseif ($winnerId == $awayPlayerId) {
                $winner = $playerCache[$awayPlayerId];
                $loser = $playerCache[$homePlayerId];
            } else {
                $winner = $playerCache[$homePlayerId];
                $loser = $playerCache[$awayPlayerId];
                $winParam = 0.5;
                $loseParam = 0.5;
            }

            $pointDifference = abs($homeScore - $awayScore);
            $multiplier = log($pointDifference + 1) * (2.2 / (($winner['elo'] - $loser['elo']) * 0.001 + 2.2));

            $winnerNewElo = $winner['elo'] + (int) (((800 / $winner['gamesPlayed']) * ($winParam - 1 / (1 + pow(10, (($loser['elo'] - $winner['elo']) / 400))))) * $multiplier);
            $loserNewElo = $loser['elo'] + (int) (((800 / $winner['gamesPlayed']) * ($loseParam - 1 / (1 + pow(10, (($winner['elo'] - $loser['elo']) / 400))))) * $multiplier);

            if ($winnerId == $awayPlayerId) {
                $playerCache[$awayPlayerId]['elo'] = $winnerNewElo;
                $playerCache[$awayPlayerId]['gamesPlayed']++;
                $playerCache[$awayPlayerId]['oldElo'] = $oldAwayElo;
                $playerCache[$homePlayerId]['elo'] = $loserNewElo;
                $playerCache[$homePlayerId]['gamesPlayed']++;
                $playerCache[$homePlayerId]['oldElo'] = $oldHomeElo;
            } else { // draw and home win handles the same
                $playerCache[$homePlayerId]['elo'] = $winnerNewElo;
                $playerCache[$homePlayerId]['gamesPlayed']++;
                $playerCache[$homePlayerId]['oldElo'] = $oldHomeElo;
                $playerCache[$awayPlayerId]['elo'] = $loserNewElo;
                $playerCache[$awayPlayerId]['gamesPlayed']++;
                $playerCache[$awayPlayerId]['oldElo'] = $oldAwayElo;
            }

            $gameRepository->updateGameElo((int) $gameId, $oldHomeElo, $oldAwayElo, $playerCache[$homePlayerId]['elo'], $playerCache[$awayPlayerId]['elo']);
        }

        foreach ($playerCache as $playerId => $player) {
            $elo = $player['elo'];
            $oldElo = $player['oldElo'];
            $gameRepository->updatePlayerElo($playerId, $elo, $oldElo);
        }

        return $this->sendJsonResponse($playerCache);
    }

    public function broadcast($id)
    {
        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);
        $data = $gameRepository->loadById($id);

        if ($data['scores']) {
            # if we have scores, match was broadcasted
            return $this->sendJsonResponse([]);
        }

        $message = '';

        if ($data) {
            $message .= "> :table_tennis_paddle_and_ball: Official match starting\n";
            $message .= "> *" . $data['groupName'] . "*: " . $data['homeSlackName'] . " *vs* " . $data['awaySlackName'] . "\n";
            $message .= "> <" . $this->guiUrl . "/#/match/" . $id . "/spectate|Spectate>";
        }

        if ($message) {
            $payload = [
                'text' => $message,
                'method' => 'post',
                'contentType' => 'application/json',
                'muteHttpExceptions' => true,
                'link_names' => 1,
                'username' => 'tabletennisbot',
                'icon_emoji' => ':table_tennis_paddle_and_ball:'
            ];

            $this->postSlackMessage($payload);
        }

        return $this->sendJsonResponse([]);
    }

    /**
     * @param $data
     * @return bool|string
     */
    private function postSlackMessage($data)
    {
        if ($this->slackKey) {
            $data_string = json_encode($data);

            $ch = curl_init($this->slackKey);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data_string))
            );

            return curl_exec($ch);
        }
    }
}
