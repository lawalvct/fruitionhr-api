<?php

namespace App\Core\Documents\Controllers;

use App\Core\Documents\Models\Document;
use App\Core\Documents\Resources\DocumentResource;
use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Owner types documents can attach to, with the permissions required to
     * manage (upload/delete) and view (list/download) them.
     */
    private const OWNER_TYPES = [
        'employee' => [
            'class' => Employee::class,
            'manage' => 'employees.update',
            'view' => 'employees.view',
        ],
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'owner_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::OWNER_TYPES))],
            'owner_id' => ['required', 'integer'],
        ]);

        $owner = $this->resolveOwner($request, $validated['owner_type'], (int) $validated['owner_id'], 'view');

        $documents = Document::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->with('uploader')
            ->latest()
            ->get();

        return DocumentResource::collection($documents);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'owner_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::OWNER_TYPES))],
            'owner_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date'],
            'file' => [
                'required', 'file', 'max:10240', // 10 MB
                'mimes:pdf,png,jpg,jpeg,doc,docx,xls,xlsx',
            ],
        ]);

        $owner = $this->resolveOwner($request, $validated['owner_type'], (int) $validated['owner_id'], 'manage');

        $tenantId = app(CurrentTenant::class)->id();
        $file = $request->file('file');
        $path = $file->store("tenants/{$tenantId}/{$validated['owner_type']}/{$owner->getKey()}", 'local');

        $document = Document::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'document_type' => $validated['document_type'] ?? null,
            'title' => $validated['title'],
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => (string) $file->getMimeType(),
            'uploaded_by' => $request->user()->id,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return (new DocumentResource($document->load('uploader')))
            ->response()
            ->setStatusCode(201);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authorizeDocument($request, $document, 'view');

        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    public function destroy(Request $request, Document $document)
    {
        $this->authorizeDocument($request, $document, 'manage');

        $document->delete(); // soft delete; file retained for audit/restore

        return response()->json(['message' => 'Document deleted.']);
    }

    private function resolveOwner(Request $request, string $ownerType, int $ownerId, string $ability): object
    {
        $config = self::OWNER_TYPES[$ownerType];

        abort_unless($request->user()->can($config[$ability]), 403);

        // Tenant scope on the owner model guarantees cross-tenant ids 404.
        return $config['class']::query()->findOrFail($ownerId);
    }

    private function authorizeDocument(Request $request, Document $document, string $ability): void
    {
        foreach (self::OWNER_TYPES as $config) {
            if ($document->owner_type === (new $config['class'])->getMorphClass()) {
                abort_unless($request->user()->can($config[$ability]), 403);

                return;
            }
        }

        abort(403);
    }
}
