export interface OfficialJournal {
    id: string;
    title: string;
    publication_date: string | null;
    file_path: string | null;
    transcription_status: string;
    is_published: boolean;
    legal_documents_count?: number;
    created_at: string;
    legal_documents?: AttachedLegalDocument[];
}

export interface AttachedLegalDocument {
    id: string;
    titre_officiel: string;
    reference_nor: string | null;
    statut: string;
    official_journal_id: string | null;
}

export interface AvailableDocument {
    id: string;
    titre_officiel: string;
    reference_nor: string | null;
}
