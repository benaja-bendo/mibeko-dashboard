import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Bell, Search, User } from 'lucide-react';

export default function DroitCongoHeader() {
    return (
        <header className="h-16 border-b border-border bg-card flex items-center px-6 gap-6">
            {/* Logo */}
            <div className="flex items-center gap-3">
                <div className="flex items-center justify-center w-10 h-10 bg-primary rounded-lg">
                    <span className="text-primary-foreground font-bold text-lg">DC</span>
                </div>
                <div>
                    <h1 className="text-lg font-bold leading-none">DroitCongo</h1>
                    <p className="text-xs text-muted-foreground leading-none mt-0.5">
                        Tableau de Bord
                    </p>
                </div>
            </div>

            {/* Global Search */}
            <div className="flex-1 max-w-2xl mx-auto">
                <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <Input
                        placeholder="Rechercher dans tous les documents juridiques..."
                        className="pl-10 bg-input"
                    />
                </div>
            </div>

            {/* Actions */}
            <div className="flex items-center gap-3">
                {/* Notifications */}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="relative">
                            <Bell className="h-5 w-5" />
                            <Badge
                                variant="destructive"
                                className="absolute -top-1 -right-1 h-5 w-5 flex items-center justify-center p-0 text-xs"
                            >
                                3
                            </Badge>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-80">
                        <div className="p-2">
                            <p className="text-sm font-semibold mb-2">Notifications</p>
                            <div className="space-y-2">
                                <div className="p-2 rounded hover:bg-accent cursor-pointer">
                                    <p className="text-sm">Nouvelle jurisprudence ajoutée</p>
                                    <p className="text-xs text-muted-foreground">Il y a 2 heures</p>
                                </div>
                                <div className="p-2 rounded hover:bg-accent cursor-pointer">
                                    <p className="text-sm">Mise à jour du Code Foncier</p>
                                    <p className="text-xs text-muted-foreground">Hier</p>
                                </div>
                                <div className="p-2 rounded hover:bg-accent cursor-pointer">
                                    <p className="text-sm">Rapport mensuel disponible</p>
                                    <p className="text-xs text-muted-foreground">Il y a 3 jours</p>
                                </div>
                            </div>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>

                {/* User Profile */}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon">
                            <div className="w-8 h-8 rounded-full bg-primary flex items-center justify-center">
                                <User className="h-4 w-4 text-primary-foreground" />
                            </div>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem>Profil</DropdownMenuItem>
                        <DropdownMenuItem>Paramètres</DropdownMenuItem>
                        <DropdownMenuItem>Déconnexion</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
