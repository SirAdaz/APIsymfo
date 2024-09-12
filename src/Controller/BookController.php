<?php
// src\Controller\BookController.php
namespace App\Controller;

use App\Entity\Book;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class BookController extends AbstractController
{
    #[Route('/api/books', name: 'books', methods: ['GET'])]
    public function getAllBooks(BookRepository $bookRepository, SerializerInterface $serializer): JsonResponse
    {
        $bookList = $bookRepository->findAll();
        $jsonBookList = $serializer->serialize($bookList, 'json', ['groups' => 'getBooks']);
        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }
    #[Route('/api/books/{id}', name: 'detailBook', methods: ['GET'])]
    public function getDetailBook(Book $book, SerializerInterface
    $serializer): JsonResponse
    {
        $jsonBook = $serializer->serialize($book, 'json', ['groups' => 'getBooks']);
        return new JsonResponse(
            $jsonBook,
            Response::HTTP_OK,
            [],
            true
        );
    }
    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    public function deleteBook(Book $book, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($book);
        $em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
    #[Route('/api/books', name: "createBook", methods: ['POST'])]
    public function createBook(Request $request,SerializerInterface $serializer,EntityManagerInterface $em,UrlGeneratorInterface $urlGenerator,AuthorRepository $authorRepository): JsonResponse {
        {
            $book = $serializer->deserialize($request->getContent(),
            Book::class, 'json');
            // Récupération de l'ensemble des données envoyées sousforme de tableau
            $content = $request->toArray();
            // Récupération de l'idAuthor. S'il n'est pas défini, alorson met -1 par défaut.
            $idAuthor = $content['idAuthor'] ?? -1;
            // On cherche l'auteur qui correspond et on l'assigne aulivre.
            // Si "find" ne trouve pas l'auteur, alors null seraretourné.
            $book->setAuthor($authorRepository->find($idAuthor));
            $em->persist($book);
            $em->flush();
            $jsonBook = $serializer->serialize($book, 'json', ['groups'=> 'getBooks']);
            $location = $urlGenerator->generate('detailBook', ['id' =>
            $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            return new JsonResponse($jsonBook, Response::HTTP_CREATED,
            ["Location" => $location], true);
    }
}
}