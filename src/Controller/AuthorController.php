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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class AuthorController extends AbstractController
{
    #[Route('/api/authors', name: 'authors', methods: ['GET'])]
    public function getAllAuthors(AuthorRepository $authorRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache): JsonResponse
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 3);
        // Permet de faire une mise en cache différenciée par page et limit : 
        $idCache = "getAllAuthors-" . $page . "-" . $limit;

        $jsonAuthorList = $cache->get($idCache, function (ItemInterface $item) use ($authorRepository, $page, $limit, $serializer) {
            $item->tag("authorsCache");
            $item->expiresAfter(60);
            $authorList = $authorRepository->findAllWithPagination($page, $limit);
            $context = SerializationContext::create()->setGroups(['getBooks']);
            return $serializer->serialize(
                $authorList,
                'json',
                $context
            );
        });

        return new JsonResponse(
            $jsonAuthorList,
            Response::HTTP_OK,
            [],
            true
        );
    }

    #[Route('api/authors/{id}', name: 'detailAuthor', methods: ['GET'])]
    public function getDetailAuthor(int $id, AuthorRepository $authorRepository, SerializerInterface $serializer): JsonResponse
    {
        $author = $authorRepository->find($id);
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonAuthor = $serializer->serialize($author, 'json', $context);

        return new JsonResponse(
            $jsonAuthor,
            Response::HTTP_OK,
            [],
            true
        );
        return new JsonResponse(null, Response::HTTP_NOT_FOUND);
    }

    // METHODE DELETE
    /**
     * Cette méthode supprime un auteur en fonction de son id. 
     * En cascade, les livres associés aux auteurs seront aux aussi supprimés. 
     *
     * /!\ Attention /!\
     * pour éviter le problème :
     * "1451 Cannot delete or update a parent row: a foreign key constraint fails"
     * Il faut bien penser rajouter dans l'entité Book, au niveau de l'author :
     * #[ORM\JoinColumn(onDelete:"CASCADE")]
     * 
     * Et resynchronizer la base de données pour appliquer ces modifications. 
     * avec : php bin/console doctrine:schema:update --force **/
    #[Route('/api/authors/{id}', name: 'deleteAuthor', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour supprimer un livre')]
    public function deleteAuthor(Author $author, EntityManagerInterface $em, TagAwareCacheInterface $cachePool): JsonResponse
    {
        $cachePool->invalidateTags(["booksCache"]);
        $em->remove($author);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // METHODE POST 
    #[Route('/api/authors', name: "createAuthor", methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour créer un livre')]
    public function createAuthor(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, ValidatorInterface $validator): JsonResponse
    {
        $author = $serializer->deserialize(
            $request->getContent(),
            Author::class,
            'json'
        );

        // On vérifie les erreurs
        $errors = $validator->validate($author);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize(
                $errors,
                'json'
            ), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        $em->persist($author);
        $em->flush();

        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonAuthor = $serializer->serialize($author, 'json', $context);
        $location = $urlGenerator->generate('detailAuthor', ['id' => $author->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        return new JsonResponse(
            $jsonAuthor,
            Response::HTTP_CREATED,
            ["Location" => $location],
            true
        );
    }

    // METHODE PUT (pour la modification d'un auteur)
    #[Route('/api/authors/{id}', name: 'updateAuthor', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour éditer un livre')]
    public function updateAuthor(Request $request, SerializerInterface $serializer, Author $currentAuthor, EntityManagerInterface $em, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse
    {
        $newAuthor = $serializer->deserialize(
            $request->getContent(),
            Author::class,
            'json',
        );
        $currentAuthor->setFirstName($newAuthor->getstName());
        $currentAuthor->setLastName($newAuthor->getLastName());


        $em->persist($currentAuthor);
        $em->flush();

        // On vide le cache.
        $cache->invalidateTags(["booksCache"]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
