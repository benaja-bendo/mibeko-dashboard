import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ChevronLeft, ChevronRight, Search } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Utilisateurs',
        href: '/users',
    },
];

interface User {
    id: string;
    name: string;
    email: string;
    created_at: string;
    status: 'active' | 'suspended' | 'pending';
    last_seen_at: string | null;
    is_online: boolean;
    roles: string[];
}

interface Props {
    users: {
        data: User[];
        links: any[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    availableRoles: string[];
    filters: {
        search?: string;
        status?: string;
    };
}

export default function Index({ users, availableRoles, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    // Debounce search
    useEffect(() => {
        const timer = setTimeout(() => {
            if (search !== (filters.search || '') || status !== (filters.status || 'all')) {
                router.get('/users', { 
                    search, 
                    status: status === 'all' ? undefined : status 
                }, { preserveState: true, replace: true });
            }
        }, 300);
        return () => clearTimeout(timer);
    }, [search, status]);

    const handleRoleChange = (userId: string, newRole: string) => {
        router.put(`/users/${userId}`, { role: newRole }, { preserveScroll: true });
    };

    const handleStatusChange = (userId: string, newStatus: string) => {
        router.put(`/users/${userId}`, { status: newStatus }, { preserveScroll: true });
    };

    const getStatusBadgeVariant = (s: string) => {
        switch (s) {
            case 'active':
                return 'default';
            case 'suspended':
                return 'destructive';
            case 'pending':
                return 'secondary';
            default:
                return 'outline';
        }
    };

    const getStatusLabel = (s: string) => {
        switch (s) {
            case 'active':
                return 'Actif';
            case 'suspended':
                return 'Suspendu';
            case 'pending':
                return 'En attente';
            default:
                return s;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Gestion des Utilisateurs" />
            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Utilisateurs</h1>
                        <p className="text-muted-foreground">
                            Gérez les comptes, rôles et accès des utilisateurs.
                        </p>
                    </div>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex flex-col md:flex-row justify-between gap-4">
                            <CardTitle>Liste des utilisateurs</CardTitle>
                            <div className="flex items-center gap-2">
                                <div className="relative w-64">
                                    <Search className="absolute left-2 top-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Rechercher..."
                                        className="pl-8"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                    />
                                </div>
                                <Select value={status} onValueChange={setStatus}>
                                    <SelectTrigger className="w-[180px]">
                                        <SelectValue placeholder="Filtrer par statut" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Tous les statuts</SelectItem>
                                        <SelectItem value="active">Actif</SelectItem>
                                        <SelectItem value="pending">En attente</SelectItem>
                                        <SelectItem value="suspended">Suspendu</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Utilisateur</TableHead>
                                    <TableHead>Statut de connexion</TableHead>
                                    <TableHead>Rôle</TableHead>
                                    <TableHead>Statut du compte</TableHead>
                                    <TableHead>Date d'inscription</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.data.length > 0 ? (
                                    users.data.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell>
                                                <div className="font-medium">{user.name}</div>
                                                <div className="text-xs text-muted-foreground">{user.email}</div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <span className={`h-2.5 w-2.5 rounded-full ${user.is_online ? 'bg-green-500' : 'bg-gray-300'}`}></span>
                                                    <span className="text-sm text-muted-foreground">
                                                        {user.is_online ? 'En ligne' : (user.last_seen_at ? `Vu le ${user.last_seen_at.split(' ')[0]}` : 'Jamais connecté')}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Select 
                                                    defaultValue={user.roles[0] || ''} 
                                                    onValueChange={(val) => handleRoleChange(user.id, val)}
                                                >
                                                    <SelectTrigger className="w-[140px] h-8 text-xs">
                                                        <SelectValue placeholder="Sélectionner un rôle" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {availableRoles.map(role => (
                                                            <SelectItem key={role} value={role}>
                                                                {role === 'admin' ? 'Admin' : role === 'editor' ? 'Éditeur' : role === 'mobile_user' ? 'Utilisateur Mobile' : role}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </TableCell>
                                            <TableCell>
                                                <Select 
                                                    defaultValue={user.status} 
                                                    onValueChange={(val) => handleStatusChange(user.id, val)}
                                                >
                                                    <SelectTrigger className="w-[130px] h-8 text-xs border-none shadow-none focus:ring-0 p-0">
                                                        <Badge variant={getStatusBadgeVariant(user.status)} className="cursor-pointer">
                                                            {getStatusLabel(user.status)}
                                                        </Badge>
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="active">Actif</SelectItem>
                                                        <SelectItem value="pending">En attente</SelectItem>
                                                        <SelectItem value="suspended">Suspendu</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                                                {user.created_at.split(' ')[0]}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                ) : (
                                    <TableRow>
                                        <TableCell colSpan={5} className="h-24 text-center">
                                            Aucun utilisateur trouvé.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>

                        {/* Pagination */}
                        <div className="flex items-center justify-end space-x-2 py-4">
                            <div className="text-sm text-muted-foreground pr-4">
                                Page {users.current_page} sur {users.last_page}
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                disabled={!users.prev_page_url}
                            >
                                <Link
                                    href={users.prev_page_url || '#'}
                                    className={!users.prev_page_url ? 'pointer-events-none opacity-50' : ''}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                disabled={!users.next_page_url}
                            >
                                <Link
                                    href={users.next_page_url || '#'}
                                    className={!users.next_page_url ? 'pointer-events-none opacity-50' : ''}
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