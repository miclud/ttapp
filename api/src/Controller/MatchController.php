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
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function finishSet($matchId)
    {
        $em = $this->getDoctrine()->getManager();

        /** @var GameRepository $gameRepository */
        $gameRepository = $this->getDoctrine()->getRepository(Game::class);
        /** @var ScoresRepository $gameRepository */
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

        if ($match->getIsFinished() == 1) {
            $em->persist($match);
            $em->flush();

            $data = $gameRepository->loadById($matchId);

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

    public function saveMatch(Request $request)
    {
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

        $em = $this->getDoctrine()->getManager();

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

        if ($data['post2Channel'] && true === $data['post2Channel']) {
            $textSetsScore = join(', ', $textSetsScore);
            $text =
                $match->getHomePlayer()->getSlackName() . ' - ' . $match->getAwayPlayer()->getSlackName() .
                ' ' .
                $match->getHomeScore() . ' - ' . $match->getAwayScore() .
                ' (' . $textSetsScore . ')';
            $message = [
                'text' => $text
            ];
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
}
