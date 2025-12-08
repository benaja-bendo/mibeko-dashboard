import Logomibeko from '@/assets/logo_mibeko.svg';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-black text-sidebar-primary-foreground">
                <img src={Logomibeko} className="size-6" alt="Mibeko Logo" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                   Mibeko
                </span>
            </div>
        </>
    );
}
