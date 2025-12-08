import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { FileText, Scale } from 'lucide-react';

interface FiltersSidebarProps {
    totalResults?: number;
}

export default function FiltersSidebar({ totalResults = 154 }: FiltersSidebarProps) {
    return (
        <div className="h-full flex flex-col bg-card border-l border-border">
            <div className="p-4 border-b border-border">
                <h2 className="text-sm font-semibold">Filtres et Statistiques</h2>
            </div>
            
            <div className="flex-1 overflow-y-auto scrollbar-thin p-4 space-y-6">
                {/* Statistics */}
                <Card className="bg-accent/50 border-border">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base flex items-center gap-2">
                            <FileText className="h-4 w-4 text-primary" />
                            Résultats
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-4xl font-bold text-primary">
                            {totalResults}
                        </div>
                        <p className="text-xs text-muted-foreground mt-1">
                            Documents trouvés
                        </p>
                    </CardContent>
                </Card>

                <Separator />

                {/* Jurisdiction Filters */}
                <div className="space-y-3">
                    <h3 className="text-sm font-semibold flex items-center gap-2">
                        <Scale className="h-4 w-4" />
                        Juridiction
                    </h3>
                    <div className="space-y-2.5">
                        <div className="flex items-center space-x-2">
                            <Checkbox id="cour-supreme" defaultChecked />
                            <Label
                                htmlFor="cour-supreme"
                                className="text-sm font-normal cursor-pointer"
                            >
                                Cour Suprême
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                89
                            </Badge>
                        </div>
                        <div className="flex items-center space-x-2">
                            <Checkbox id="cour-appel" defaultChecked />
                            <Label
                                htmlFor="cour-appel"
                                className="text-sm font-normal cursor-pointer"
                            >
                                Cour d'Appel
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                45
                            </Badge>
                        </div>
                        <div className="flex items-center space-x-2">
                            <Checkbox id="tribunal-1" />
                            <Label
                                htmlFor="tribunal-1"
                                className="text-sm font-normal cursor-pointer"
                            >
                                Tribunal de Grande Instance
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                20
                            </Badge>
                        </div>
                    </div>
                </div>

                <Separator />

                {/* Document Type Filters */}
                <div className="space-y-3">
                    <h3 className="text-sm font-semibold">Type de Document</h3>
                    <div className="space-y-2.5">
                        <div className="flex items-center space-x-2">
                            <Checkbox id="type-loi" defaultChecked />
                            <Label
                                htmlFor="type-loi"
                                className="text-sm font-normal cursor-pointer"
                            >
                                Loi
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                67
                            </Badge>
                        </div>
                        <div className="flex items-center space-x-2">
                            <Checkbox id="type-decret" defaultChecked />
                            <Label
                                htmlFor="type-decret"
                                className="text-sm font-normal cursor-pointer"
                            >
                                Décret
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                52
                            </Badge>
                        </div>
                        <div className="flex items-center space-x-2">
                            <Checkbox id="type-ordonnance" />
                            <Label
                                htmlFor="type-ordonnance"
                                className="text-sm font-normal cursor-pointer"
                            >
                                Ordonnance
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                35
                            </Badge>
                        </div>
                    </div>
                </div>

                <Separator />

                {/* Year Range */}
                <div className="space-y-3">
                    <h3 className="text-sm font-semibold">Période</h3>
                    <div className="space-y-2.5">
                        <div className="flex items-center space-x-2">
                            <Checkbox id="year-2024" defaultChecked />
                            <Label
                                htmlFor="year-2024"
                                className="text-sm font-normal cursor-pointer"
                            >
                                2024
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                42
                            </Badge>
                        </div>
                        <div className="flex items-center space-x-2">
                            <Checkbox id="year-2023" defaultChecked />
                            <Label
                                htmlFor="year-2023"
                                className="text-sm font-normal cursor-pointer"
                            >
                                2023
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                38
                            </Badge>
                        </div>
                        <div className="flex items-center space-x-2">
                            <Checkbox id="year-2022" />
                            <Label
                                htmlFor="year-2022"
                                className="text-sm font-normal cursor-pointer"
                            >
                                2022
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                31
                            </Badge>
                        </div>
                        <div className="flex items-center space-x-2">
                            <Checkbox id="year-2021" />
                            <Label
                                htmlFor="year-2021"
                                className="text-sm font-normal cursor-pointer"
                            >
                                2021
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                27
                            </Badge>
                        </div>
                        <div className="flex items-center space-x-2">
                            <Checkbox id="year-2020" />
                            <Label
                                htmlFor="year-2020"
                                className="text-sm font-normal cursor-pointer"
                            >
                                2020
                            </Label>
                            <Badge variant="secondary" className="ml-auto text-xs">
                                16
                            </Badge>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
