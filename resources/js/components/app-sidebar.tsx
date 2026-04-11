import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, FileText, Folder, History, LayoutGrid, Users } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const roles = (auth.user?.roles as string[]) || [];
    const isAdmin = roles.includes('admin') || roles.includes('editor');
    const isSuperAdmin = roles.includes('admin');

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        ...(isAdmin ? [
            {
                title: 'Documents',
                href: '/curation',
                icon: BookOpen,
            },
            {
                title: 'Journal Officiel',
                href: '/official-journals',
                icon: FileText,
            },
            {
                title: 'Auditing',
                href: '/auditing',
                icon: History,
            },
        ] : []),
        ...(isSuperAdmin ? [
            {
                title: 'Utilisateurs',
                href: '/users',
                icon: Users,
            },
        ] : []),
    ];

    const footerNavItems: NavItem[] = [
        {
            title: 'Repository',
            href: 'https://github.com/benaja-bendo/mibeko-dashboard',
            icon: Folder,
        },
        ...(isAdmin ? [
            {
                title: 'Documentation API',
                href: '/docs/api',
                icon: BookOpen,
            },
        ] : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
