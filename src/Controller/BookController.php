<?php

namespace App\Controller;

use App\Entity\Book;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use OpenApi\Annotations as OA; // Ajout de l'import pour les annotations OpenAPI
use Nelmio\ApiDocBundle\Annotation\Model; // Ajout de l'import pour Model dans les annotations

class BookController extends AbstractController
{
    /**
     * Cette méthode permet de récupérer l'ensemble des livres.
     *
     * @OA\Get(
     *     path="/api/books",
     *     summary="Récupère la liste des livres",
     *     @OA\Response(
     *         response=200,
     *         description="Retourne la liste des livres",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref=@Model(type=Book::class, groups={"getBooks"}))
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
     *     @OA\Tag(name="Books")
     * )
     * @param BookRepository $bookRepository
     * @param SerializerInterface $serializer
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/api/books', name: 'books', methods: ['GET'])]
    public function getAllBooks(BookRepository $bookRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache): JsonResponse
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 3);
        $idCache = "getAllBooks-" . $page . "-" . $limit;

        $jsonBookList = $cache->get($idCache, function (ItemInterface $item) use ($bookRepository, $page, $limit, $serializer) {
            $item->tag("booksCache");
            $item->expiresAfter(60);
            $bookList = $bookRepository->findAllWithPagination($page, $limit);
            $context = SerializationContext::create()->setGroups(['getBooks']);
            $context->setVersion('1.0');
            return $serializer->serialize($bookList, 'json', $context);
        });

        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthode permet de récupérer le détail d'un livre.
     *
     * @OA\Get(
     *     path="/api/books/{id}",
     *     summary="Récupère le détail d'un livre",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID du livre",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Retourne les détails du livre",
     *         @OA\JsonContent(ref=@Model(type=Book::class, groups={"getBooks"}))
     *     ),
     *     @OA\Tag(name="Books")
     * )
     */
    #[Route('/api/books/{id}', name: 'detailBook', methods: ['GET'])]
    public function getDetailBook(Book $book, SerializerInterface $serializer, VersioningService $versioningService): JsonResponse
    {
        $version = $versioningService->getVersion();
        $context = SerializationContext::create()->setGroups(["getBooks"]);
        $context->setVersion($version);
        $jsonBook = $serializer->serialize($book, 'json', $context);

        return new JsonResponse($jsonBook, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthode permet de supprimer un livre.
     *
     * @OA\Delete(
     *     path="/api/books/{id}",
     *     summary="Supprime un livre",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID du livre",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Livre supprimé avec succès"
     *     ),
     *     security={{"bearerAuth":{}}},
     *     @OA\Tag(name="Books")
     * )
     */
    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour supprimer un livre')]
    public function deleteBook(Book $book, EntityManagerInterface $em, TagAwareCacheInterface $cachePool): JsonResponse
    {
        $cachePool->invalidateTags(["booksCache"]);
        $em->remove($book);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Cette méthode permet de créer un nouveau livre.
     *
     * @OA\Post(
     *     path="/api/books",
     *     summary="Crée un nouveau livre",
     *     @OA\RequestBody(
     *         description="Les données du livre à créer",
     *         required=true,
     *         @OA\JsonContent(ref=@Model(type=Book::class, groups={"createBook"}))
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Livre créé avec succès",
     *         @OA\JsonContent(ref=@Model(type=Book::class, groups={"getBooks"}))
     *     ),
     *     security={{"bearerAuth":{}}},
     *     @OA\Tag(name="Books")
     * )
     */
    #[Route('/api/books', name: 'createBook', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour créer un livre')]
    public function createBook(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, AuthorRepository $authorRepository, ValidatorInterface $validator): JsonResponse
    {
        $book = $serializer->deserialize($request->getContent(), Book::class, 'json');

        $errors = $validator->validate($book);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }
        $em->persist($book);
        $em->flush();

        $content = $request->toArray();
        $idAuthor = $content['idAuthor'] ?? -1;
        $book->setAuthor($authorRepository->find($idAuthor));

        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonBook = $serializer->serialize($book, 'json', $context);
        $location = $urlGenerator->generate('detailBook', ['id' => $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        return new JsonResponse($jsonBook, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    /**
     * Cette méthode permet de mettre à jour un livre.
     *
     * @OA\Put(
     *     path="/api/books/{id}",
     *     summary="Met à jour un livre",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID du livre à mettre à jour",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         description="Les données du livre à mettre à jour",
     *         required=true,
     *         @OA\JsonContent(ref=@Model(type=Book::class, groups={"createBook"}))
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Livre mis à jour avec succès"
     *     ),
     *     security={{"bearerAuth":{}}},
     *     @OA\Tag(name="Books")
     * )
     */
    #[Route('/api/books/{id}', name: "updateBook", methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour éditer un livre')]
    public function updateBook(Request $request, SerializerInterface $serializer, Book $currentBook, EntityManagerInterface $em, AuthorRepository $authorRepository, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse
    {
        $newBook = $serializer->deserialize($request->getContent(), Book::class, 'json');
        $currentBook->setTitle($newBook->getTitle());
        $currentBook->setCoverText($newBook->getCoverText());

        $errors = $validator->validate($currentBook);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        $content = $request->toArray();
        $idAuthor = $content['idAuthor'] ?? -1;
        $currentBook->setAuthor($authorRepository->find($idAuthor));

        $em->persist($currentBook);
        $em->flush();

        $cache->invalidateTags(["booksCache"]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
