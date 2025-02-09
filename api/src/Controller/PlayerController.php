<?php

namespace App\Controller;

use App\Entity\Player;
use App\Repository\PlayerRepository;
use Doctrine\DBAL\DBALException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Serializer;

class PlayerController extends BaseController
{
    const STARTING_ELO = 1500;

    /**
     * @param $id
     * @return Response
     * @throws DBALException
     */
    public function getPlayerById($id)
    {
        /** @var PlayerRepository $playerRepository */
        $playerRepository = $this->getDoctrine()->getRepository(Player::class);
        $player = $playerRepository->loadPlayerById($id);

        if (!$player) {
            throw $this->createNotFoundException(
                'Player not found with id: ' . $id
            );
        }

        return $this->sendJsonResponse($player);
    }

    /**
     * @param $id
     * @return Response
     * @throws DBALException
     */
    public function getPlayerResults($id)
    {
        /** @var PlayerRepository $playerRepository */
        $playerRepository = $this->getDoctrine()->getRepository(Player::class);
        $player = $playerRepository->loadPlayerResults($id);

        if (!$player) {
            $player = [];
        }

        return $this->sendJsonResponse($player);
    }

    public function getPlayerSchedule($id)
    {
        /** @var PlayerRepository $playerRepository */
        $playerRepository = $this->getDoctrine()->getRepository(Player::class);

        $player = $playerRepository->loadPlayerSchedule($id);

        return $this->sendJsonResponse($player);
    }

    /**
     * List of all players
     *
     * @return Response
     */
    public function getPlayers()
    {
        /** @var PlayerRepository $playerRepository */
        $playerRepository = $this->getDoctrine()->getRepository(Player::class);
        $players = $playerRepository->loadAllPlayers();

        if (!$players) {
            throw $this->createNotFoundException(
                'No players found'
            );
        }

        return $this->sendJsonResponse($players);
    }

    /**
     * @param Request $request
     * @return JsonResponse|Response
     */
    public function addPlayer(Request $request)
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['name'])) {
            return new JsonResponse([
                'status' => 'error',
                'errorText' => 'Fill the form'
            ],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        if (!empty($data['name'])) {
            $em = $this->getDoctrine()->getManager();
            $player = new Player();
            $player->setName($data['name']);
            $player->setNickname($data['nickname']);
            $player->setTournamentElo(self::STARTING_ELO);
            $player->setCurrentElo(self::STARTING_ELO);
            $em->persist($player);
            $em->flush();

            return new Response($player->getId());
        }
    }
}