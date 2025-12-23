import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Auditing',
        href: '/auditing',
    },
];

interface Audit {
    id: number;
    user: {
        name: string;
        email: string;
    } | null;
    event: string;
    auditable_type: string;
    auditable_id: string;
    old_values: Record<string, any>;
    new_values: Record<string, any>;
    created_at: string;
}

interface Props {
    audits: {
        data: Audit[];
        links: any[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}

export default function Index({ audits }: Props) {
    const getBadgeVariant = (event: string) => {
        switch (event) {
            case 'created':
                return 'default';
            case 'updated':
                return 'secondary';
            case 'deleted':
                return 'destructive';
            default:
                return 'outline';
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Auditing" />
            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Auditing</h1>
                    <p className="text-muted-foreground">
                        Suivez les modifications apportées à l'application.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Journal d'audit</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Utilisateur</TableHead>
                                    <TableHead>Événement</TableHead>
                                    <TableHead>Cible</TableHead>
                                    <TableHead>Modifications</TableHead>
                                    <TableHead>Date</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {audits.data.length > 0 ? (
                                    audits.data.map((audit) => (
                                        <TableRow key={audit.id}>
                                            <TableCell>
                                                {audit.user ? (
                                                    <div>
                                                        <div className="font-medium">{audit.user.name}</div>
                                                        <div className="text-xs text-muted-foreground">{audit.user.email}</div>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground">Système</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={getBadgeVariant(audit.event)}>
                                                    {audit.event}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <div className="font-medium">{audit.auditable_type}</div>
                                                <div className="text-xs text-muted-foreground italic truncate max-w-[150px]">
                                                    {audit.auditable_id}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="text-xs space-y-1">
                                                    {Object.keys(audit.new_values).map((key) => {
                                                        const newVal = audit.new_values[key];
                                                        const oldVal = audit.old_values[key];
                                                        
                                                        // Truncate long values
                                                        const formatVal = (val: any) => {
                                                            if (typeof val === 'string' && val.length > 50) {
                                                                return val.substring(0, 50) + '...';
                                                            }
                                                            return JSON.stringify(val);
                                                        };

                                                        return (
                                                            <div key={key} className="border-l-2 border-primary/20 pl-2 py-0.5">
                                                                <span className="font-semibold">{key}</span>: 
                                                                {audit.event === 'updated' ? (
                                                                    <>
                                                                        <span className="text-muted-foreground line-through ml-1">{formatVal(oldVal)}</span>
                                                                        <span className="text-primary ml-1">→ {formatVal(newVal)}</span>
                                                                    </>
                                                                ) : (
                                                                    <span className="text-primary ml-1">{formatVal(newVal)}</span>
                                                                )}
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {audit.created_at}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                ) : (
                                    <TableRow>
                                        <TableCell colSpan={5} className="h-24 text-center">
                                            Aucun audit trouvé.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>

                        {/* Pagination */}
                        <div className="flex items-center justify-end space-x-2 py-4">
                            <div className="text-sm text-muted-foreground pr-4">
                                Page {audits.current_page} sur {audits.last_page}
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                disabled={!audits.prev_page_url}
                            >
                                <Link
                                    href={audits.prev_page_url || '#'}
                                    className={!audits.prev_page_url ? 'pointer-events-none opacity-50' : ''}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                disabled={!audits.next_page_url}
                            >
                                <Link
                                    href={audits.next_page_url || '#'}
                                    className={!audits.next_page_url ? 'pointer-events-none opacity-50' : ''}
                                >
                                    Suivant
                                    <ChevronRight className="h-4 w-4" />
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
