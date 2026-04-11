<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Services\OfficialJournalService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OfficialJournalController extends Controller
{
    public function __construct(protected OfficialJournalService $journalService) {}

    /**
     * Affiche la liste des journaux officiels dans le tableau de bord.
     */
    public function index(Request $request)
    {
        $journals = OfficialJournal::query()
            ->withCount('legalDocuments')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return Inertia::render('OfficialJournals/Index', [
            'journals' => $journals,
        ]);
    }

    /**
     * Enregistre un nouveau journal officiel et upload le PDF.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'publication_date' => ['nullable', 'date'],
            'is_published' => ['boolean'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:51200'], // 50MB max
        ]);

        $this->journalService->uploadAndCreate($validated, $request->file('file'));

        return redirect()->back()->with('success', 'Journal Officiel créé avec succès.');
    }

    /**
     * Affiche les détails d'un journal officiel.
     */
    public function show(OfficialJournal $officialJournal)
    {
        $officialJournal->load(['legalDocuments' => function ($query) {
            $query->select('id', 'titre_officiel', 'reference_nor', 'statut', 'official_journal_id');
        }]);

        // Optionnel : passer une liste de documents non liés pour faciliter l'attachement
        // Limitons à 50 pour la performance, l'utilisateur pourra chercher via un endpoint dédié si besoin
        $availableDocuments = LegalDocument::query()
            ->whereNull('official_journal_id')
            ->select('id', 'titre_officiel', 'reference_nor')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return Inertia::render('OfficialJournals/Show', [
            'journal' => $officialJournal,
            'availableDocuments' => $availableDocuments,
        ]);
    }

    /**
     * Met à jour les informations du journal officiel (et potentiellement le fichier).
     */
    public function update(Request $request, OfficialJournal $officialJournal)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'publication_date' => ['nullable', 'date'],
            'is_published' => ['boolean'],
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:51200'], // Optionnel
        ]);

        $this->journalService->updateJournal($officialJournal, $validated, $request->file('file'));

        return redirect()->back()->with('success', 'Journal Officiel mis à jour avec succès.');
    }

    /**
     * Attache un document juridique au journal.
     */
    public function attachDocument(Request $request, OfficialJournal $officialJournal)
    {
        $validated = $request->validate([
            'legal_document_id' => ['required', 'exists:legal_documents,id'],
        ]);

        $document = LegalDocument::findOrFail($validated['legal_document_id']);
        $document->update(['official_journal_id' => $officialJournal->id]);

        return redirect()->back()->with('success', 'Document rattaché au Journal Officiel.');
    }

    /**
     * Détache un document juridique du journal.
     */
    public function detachDocument(Request $request, OfficialJournal $officialJournal, LegalDocument $legalDocument)
    {
        if ($legalDocument->official_journal_id === $officialJournal->id) {
            $legalDocument->update(['official_journal_id' => null]);
        }

        return redirect()->back()->with('success', 'Document détaché du Journal Officiel.');
    }

    /**
     * Supprime un journal officiel.
     */
    public function destroy(OfficialJournal $officialJournal)
    {
        $officialJournal->delete();

        return redirect()->back()->with('success', 'Journal Officiel supprimé avec succès.');
    }
}
