<?php

namespace App\Controller;

use App\Entity\Author;
use App\Repository\AuthorRepository;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use OpenApi\Annotations as OA; // Ajout de l'import pour les annotations OpenAPI
use Nelmio\ApiDocBundle\Annotation\Model; // Ajout de l'import pour Model dans les annotations

class AuthorController extends AbstractController
{
    /**
     * Cette méthode permet de récupérer l'ensemble des auteurs.
     *
     * @OA\Get(
     *     path="/api/authors",
     *     summary="Récupère la liste des auteurs",
     *     @OA\Response(
     *         response=200,
     *         description="Retourne la liste des auteurs",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref=@Model(type=Author::class, groups={"getBooks"}))
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="La page que l'on veut récupérer",
     *         @OA\Schema(type="int")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Le nombre d'éléments que l'on veut récupérer",
     *         @OA\Schema(type="int")
     *     ),
     *     @OA\Tag(name="Authors")
     * )
     * @param AuthorRepository $authorRepository
     * @param SerializerInterface $serializer
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/api/authors', name: 'authors', methods: ['GET'])]
    public function getAllAuthors(AuthorRepository $authorRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache): JsonResponse
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 3);
        $idCache = "getAllAuthors-" . $page . "-" . $limit;

        $jsonAuthorList = $cache->get($idCache, function (ItemInterface $item) use ($authorRepository, $page, $limit, $serializer) {
            $item->tag("authorsCache");
            $item->expiresAfter(60);
            $authorList = $authorRepository->findAllWithPagination($page, $limit);
            $context = SerializationContext::create()->setGroups(['getBooks']);
            return $serializer->serialize($authorList, 'json', $context);
        });

        return new JsonResponse($jsonAuthorList, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthode permet de récupérer le détail d'un auteur.
     *
     * @OA\Get(
     *     path="api/authors/{id}",
     *     summary="Récupère le détail d'un auteur",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de l'auteur",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Retourne les détails de l'auteur",
     *         @OA\JsonContent(ref=@Model(type=Author::class, groups={"getBooks"}))
     *     ),
     *     @OA\Tag(name="Authors")
     * )
     */
    #[Route('api/authors/{id}', name: 'detailAuthor', methods: ['GET'])]
    public function getDetailAuthor(int $id, AuthorRepository $authorRepository, SerializerInterface $serializer): JsonResponse
    {
        $author = $authorRepository->find($id);
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonAuthor = $serializer->serialize($author, 'json', $context);

        return new JsonResponse($jsonAuthor, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthode supprime un auteur en fonction de son id. 
     * En cascade, les livres associés aux auteurs seront aux aussi supprimés. 
     *
     * @OA\Delete(
     *     path="/api/authors/{id}",
     *     summary="Supprime un auteur",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de l'auteur",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Auteur supprimé avec succès"
     *     ),
     *     security={{"bearerAuth":{}}},
     *     @OA\Tag(name="Authors")
     * )
     */
    #[Route('/api/authors/{id}', name: 'deleteAuthor', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour supprimer un auteur')]
    public function deleteAuthor(Author $author, EntityManagerInterface $em, TagAwareCacheInterface $cachePool): JsonResponse
    {
        $cachePool->invalidateTags(["booksCache"]);
        $em->remove($author);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Cette méthode permet de créer un nouvel auteur.
     *
     * @OA\Post(
     *     path="/api/authors",
     *     summary="Crée un nouvel auteur",
     *     @OA\RequestBody(
     *         description="Les données de l'auteur à créer",
     *         required=true,
     *         @OA\JsonContent(ref=@Model(type=Author::class, groups={"createAuthor"}))
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Auteur créé avec succès",
     *         @OA\JsonContent(ref=@Model(type=Author::class, groups={"getBooks"}))
     *     ),
     *     security={{"bearerAuth":{}}},
     *     @OA\Tag(name="Authors")
     * )
     */
    #[Route('/api/authors', name: "createAuthor", methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour créer un auteur')]
    public function createAuthor(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, ValidatorInterface $validator): JsonResponse
    {
        $author = $serializer->deserialize($request->getContent(), Author::class, 'json');

        // On vérifie les erreurs
        $errors = $validator->validate($author);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        $em->persist($author);
        $em->flush();

        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonAuthor = $serializer->serialize($author, 'json', $context);
        $location = $urlGenerator->generate('detailAuthor', ['id' => $author->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        return new JsonResponse($jsonAuthor, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    /**
     * Cette méthode permet de mettre à jour un auteur.
     *
     * @OA\Put(
     *     path="/api/authors/{id}",
     *     summary="Met à jour un auteur",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de l'auteur à mettre à jour",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         description="Les données de l'auteur à mettre à jour",
     *         required=true,
     *         @OA\JsonContent(ref=@Model(type=Author::class, groups={"createAuthor"}))
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Auteur mis à jour avec succès"
     *     ),
     *     security={{"bearerAuth":{}}},
     *     @OA\Tag(name="Authors")
     * )
     */
    #[Route('/api/authors/{id}', name: 'updateAuthor', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour éditer un auteur')]
    public function updateAuthor(Request $request, SerializerInterface $serializer, Author $currentAuthor, EntityManagerInterface $em, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse
    {
        $newAuthor = $serializer->deserialize($request->getContent(), Author::class, 'json');
        $currentAuthor->setFirstName($newAuthor->getFirstName());
        $currentAuthor->setLastName($newAuthor->getLastName());

        $errors = $validator->validate($currentAuthor);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        $em->persist($currentAuthor);
        $em->flush();

        // On vide le cache.
        $cache->invalidateTags(["booksCache"]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
