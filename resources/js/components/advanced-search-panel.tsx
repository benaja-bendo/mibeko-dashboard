import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Search } from 'lucide-react';

interface SearchResult {
    id: string;
    title: string;
    excerpt: string;
    source: string;
    sourceType: 'loi' | 'decret' | 'code';
    date: string;
    highlightedTerms: string[];
}

interface AdvancedSearchPanelProps {
    results?: SearchResult[];
    searchTerm?: string;
}

const SAMPLE_RESULTS: SearchResult[] = [
    {
        id: '1',
        title: 'Code Foncier - Article 34',
        excerpt: "L'expropriation pour cause d'utilité publique ne peut intervenir que moyennant une juste et préalable indemnité. Le propriétaire exproprié a le droit de contester le montant de l'indemnité devant les juridictions compétentes.",
        source: 'Code Foncier',
        sourceType: 'code',
        date: '2024-03-15',
        highlightedTerms: ['expropriation', 'indemnité'],
    },
    {
        id: '2',
        title: 'Loi n° 2023-042 relative à l\'expropriation',
        excerpt: "En cas d'expropriation, l'indemnité doit couvrir la valeur réelle du bien et le préjudice subi. La procédure d'expropriation ne peut être engagée qu'après déclaration d'utilité publique.",
        source: 'Journal Officiel 2023-26',
        sourceType: 'loi',
        date: '2023-08-20',
        highlightedTerms: ['expropriation', 'indemnité', 'utilité publique'],
    },
    {
        id: '3',
        title: 'Décret n° 2022-156',
        excerpt: "Le calcul de l'indemnité d'expropriation se base sur la valeur marchande du bien au jour de la décision. Les améliorations apportées postérieurement ne sont pas prises en compte.",
        source: 'Code Foncier - Dispositions d\'application',
        sourceType: 'decret',
        date: '2022-11-10',
        highlightedTerms: ['indemnité', 'expropriation'],
    },
];

function highlightText(text: string, terms: string[]): React.ReactNode {
    if (!terms.length) return text;

    const regex = new RegExp(`(${terms.join('|')})`, 'gi');
    const parts = text.split(regex);

    return parts.map((part, index) => {
        const isHighlighted = terms.some(term =>
            part.toLowerCase() === term.toLowerCase()
        );
        return isHighlighted ? (
            <mark
                key={index}
                className="bg-primary/20 text-black font-semibold px-0.5 rounded"
            >
                {part}
            </mark>
        ) : (
            part
        );
    });
}

export default function AdvancedSearchPanel({
    results = SAMPLE_RESULTS,
    searchTerm = 'Expropriation'
}: AdvancedSearchPanelProps) {
    const sourceColors = {
        loi: 'bg-success/20 text-success border-success/30',
        decret: 'bg-primary/20 text-primary border-primary/30',
        code: 'bg-accent text-accent-foreground border-border',
    };

    return (
        <div className="space-y-6">
            {/* Advanced Search Form */}
            <Card className="border-border bg-card">
                <CardHeader>
                    <CardTitle className="text-lg">Recherche Avancée et Analyse</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="lg:col-span-2">
                            <Label htmlFor="keywords" className="text-sm">
                                Mots-clés
                            </Label>
                            <Input
                                id="keywords"
                                placeholder="Rechercher dans les textes..."
                                defaultValue={searchTerm}
                                className="mt-1.5 bg-input"
                            />
                        </div>
                        <div>
                            <Label htmlFor="date-filter" className="text-sm">
                                Date
                            </Label>
                            <Select defaultValue="all">
                                <SelectTrigger id="date-filter" className="mt-1.5 bg-input">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Toutes les dates</SelectItem>
                                    <SelectItem value="2024">2024</SelectItem>
                                    <SelectItem value="2023">2023</SelectItem>
                                    <SelectItem value="2022">2022</SelectItem>
                                    <SelectItem value="custom">Période personnalisée</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label htmlFor="jurisdiction" className="text-sm">
                                Juridiction
                            </Label>
                            <Select defaultValue="all">
                                <SelectTrigger id="jurisdiction" className="mt-1.5 bg-input">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Toutes juridictions</SelectItem>
                                    <SelectItem value="supreme">Cour Suprême</SelectItem>
                                    <SelectItem value="appel">Cour d'Appel</SelectItem>
                                    <SelectItem value="tribunal">Tribunal</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="mt-4">
                        <Button className="w-full sm:w-auto bg-primary hover:bg-primary/90">
                            <Search className="mr-2 h-4 w-4" />
                            Rechercher
                        </Button>
                    </div>
                </CardContent>
            </Card>

            {/* Search Results */}
            <div>
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="text-sm font-semibold">
                        Résultats pour "{searchTerm}"
                    </h3>
                    <span className="text-sm text-muted-foreground">
                        {results.length} résultats
                    </span>
                </div>
                <div className="space-y-3">
                    {results.map((result) => (
                        <Card
                            key={result.id}
                            className="border-border hover:border-primary/50 transition-colors cursor-pointer"
                        >
                            <CardContent className="pt-4">
                                <div className="space-y-2">
                                    <div className="flex items-start justify-between gap-3">
                                        <h4 className="font-semibold text-sm">
                                            {result.title}
                                        </h4>
                                        <Badge
                                            variant="outline"
                                            className={`shrink-0 ${sourceColors[result.sourceType]}`}
                                        >
                                            {result.source}
                                        </Badge>
                                    </div>
                                    <div className="p-3 bg-white rounded border border-gray-200 my-2">
                                        <p className="text-sm text-black leading-relaxed font-serif">
                                            {highlightText(result.excerpt, result.highlightedTerms)}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span>Publié le {new Date(result.date).toLocaleDateString('fr-FR')}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </div>
    );
}
