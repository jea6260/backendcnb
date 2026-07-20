<?php

namespace App\Controller;

use App\Service\ResourceRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $registry,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $resources = $this->registry->all();
        $stats = [];

        foreach ($resources as $name => $definition) {
            $stats[$name] = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $definition['table']));
        }

        return $this->render('admin/dashboard.html.twig', [
            'resources' => $resources,
            'stats' => $stats,
        ]);
    }

    #[Route('/admin/{resource}', name: 'admin_resource_index', methods: ['GET'])]
    public function index(string $resource, Request $request): Response
    {
        $definition = $this->registry->get($resource);
        $fields = $this->registry->listFields($definition);
        $pk = $this->registry->primaryKey($definition);
        $sort = (string) $request->query->get('sort', $pk);
        $dir = (string) $request->query->get('dir', 'desc');
        $filters = array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $request->query->all('f')
        );
        $filters = array_filter($filters, static fn (string $value): bool => $value !== '');

        $rows = $this->registry->fetchListRows($this->connection, $definition, $sort, $dir, $filters);

        return $this->render('admin/index.html.twig', [
            'resource' => $resource,
            'definition' => $definition,
            'fields' => $fields,
            'rows' => $rows,
            'primary_key' => $pk,
            'show_id' => ($definition['list_id'] ?? true) && $pk === 'id',
            'sort' => in_array($sort, $this->registry->listableColumns($definition), true) ? $sort : $pk,
            'dir' => strtolower($dir) === 'asc' ? 'asc' : 'desc',
            'filters' => $filters,
        ]);
    }

    #[Route('/admin/{resource}/nuevo', name: 'admin_resource_new', methods: ['GET', 'POST'])]
    public function new(string $resource, Request $request): Response
    {
        $definition = $this->registry->get($resource);
        $data = [];
        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            try {
                $payload = $this->registry->payloadFor($definition, $data, form: true);
                $this->connection->insert($definition['table'], $payload);
                $this->addFlash('success', sprintf('%s creado correctamente.', $definition['singular']));

                return $this->redirectToRoute('admin_resource_index', ['resource' => $resource]);
            } catch (DbalException $exception) {
                $error = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
            }
        }

        return $this->renderCrudForm($resource, $definition, $data, $error);
    }

    #[Route('/admin/{resource}/{id}/editar', name: 'admin_resource_edit', requirements: ['id' => '[^/]+'], methods: ['GET', 'POST'])]
    public function edit(string $resource, string $id, Request $request): Response
    {
        $definition = $this->registry->get($resource);
        $pk = $this->registry->primaryKey($definition);
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ?', $definition['table'], $pk),
            [$id]
        );

        if (!$row) {
            throw $this->createNotFoundException();
        }

        $data = $this->registry->normalizeRow($row);
        $error = null;

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            try {
                $payload = $this->registry->payloadFor($definition, $data, patch: true, form: true);
                $this->connection->update($definition['table'], $payload, [$pk => $id]);
                $this->addFlash('success', sprintf('%s actualizado correctamente.', $definition['singular']));

                return $this->redirectToRoute('admin_resource_index', ['resource' => $resource]);
            } catch (DbalException $exception) {
                $error = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
            }
        }

        return $this->renderCrudForm($resource, $definition, $data, $error, $id);
    }

    #[Route('/admin/{resource}/{id}/eliminar', name: 'admin_resource_delete', requirements: ['id' => '[^/]+'], methods: ['POST'])]
    public function delete(string $resource, string $id): RedirectResponse
    {
        $definition = $this->registry->get($resource);
        $pk = $this->registry->primaryKey($definition);

        try {
            $this->connection->delete($definition['table'], [$pk => $id]);
            $this->addFlash('success', sprintf('%s eliminado correctamente.', $definition['singular']));
        } catch (DbalException $exception) {
            $this->addFlash('danger', $exception->getPrevious()?->getMessage() ?? $exception->getMessage());
        }

        return $this->redirectToRoute('admin_resource_index', ['resource' => $resource]);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     */
    private function renderCrudForm(
        string $resource,
        array $definition,
        array $data,
        ?string $error,
        string|int|null $id = null,
    ): Response {
        $fields = $this->registry->formFields($definition);
        $relations = [];

        foreach ($fields as $name => $field) {
            $relations[$name] = $this->registry->relationOptions($this->connection, $field);
        }

        return $this->render('admin/form.html.twig', [
            'resource' => $resource,
            'definition' => $definition,
            'fields' => $fields,
            'relations' => $relations,
            'data' => $data,
            'error' => $error,
            'id' => $id,
            'primary_key' => $this->registry->primaryKey($definition),
        ]);
    }
}
