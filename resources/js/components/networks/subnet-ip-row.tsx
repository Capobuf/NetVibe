import { Ban } from 'lucide-react';
import { CopyValue } from '@/components/copy-value';
import { DeviceAssociation } from '@/components/devices/device-association';
import {
    MikrotikIndicator,
    MikrotikValue,
    type MikrotikSource,
} from '@/components/networks/mikrotik-source';
import { SnipeItIp } from '@/components/networks/snipe-it-ip';
import { TagBadge } from '@/components/networks/tag-badge';
import {
    UnifiObservationIndicator,
    UnifiObservedValue,
    type UnifiObservation,
} from '@/components/networks/unifi-observation';
import type { TableColumn } from '@/components/table-columns';
import { AvatarGroup } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { IpAddress } from '@/types/network';

export type IpColumn = TableColumn<
    | 'ip'
    | 'hostname'
    | 'mac_address'
    | 'description'
    | 'status'
    | 'tags'
    | 'device'
    | 'sources'
>;

export type IpRow = Omit<IpAddress, 'id'> & {
    id: number | null;
    excluded: boolean;
};

export function SubnetIpRow({
    ipAddress,
    visibleColumns,
    sources,
    unifi,
    company,
    site,
    canEdit,
    onOpen,
}: {
    ipAddress: IpRow;
    visibleColumns: IpColumn[];
    sources: MikrotikSource[];
    unifi: UnifiObservation[];
    company: number;
    site: number;
    canEdit: boolean;
    onOpen: (ipAddress: IpRow, opener: HTMLElement) => void;
}) {
    const current = sources.filter((source) => source.state !== 'missing');
    const sourceNotice = current.some((source) => source.problem)
        ? 'Da verificare'
        : current.length > 0 && current.every((source) => source.stale)
          ? 'Non aggiornato'
          : null;
    const canOpen = ipAddress.id !== null || canEdit;

    return (
        <TableRow
            id={`ip-row-${ipAddress.ip}`}
            className={
                canOpen
                    ? 'cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring'
                    : undefined
            }
            tabIndex={canOpen ? 0 : undefined}
            aria-label={
                canOpen
                    ? `${ipAddress.id !== null ? 'Visualizza' : 'Documenta'} IP ${ipAddress.ip}`
                    : undefined
            }
            aria-haspopup={canOpen ? 'dialog' : undefined}
            onClick={
                canOpen
                    ? (event) => {
                          if (
                              event.currentTarget.contains(
                                  event.target as Node,
                              ) &&
                              window.getSelection()?.isCollapsed !== false &&
                              !(event.target as HTMLElement).closest(
                                  'a,button,input,select,textarea,[role="button"]',
                              )
                          )
                              onOpen(ipAddress, event.currentTarget);
                      }
                    : undefined
            }
            onKeyDown={
                canOpen
                    ? (event) => {
                          if (
                              event.target === event.currentTarget &&
                              (event.key === 'Enter' || event.key === ' ')
                          ) {
                              event.preventDefault();
                              onOpen(ipAddress, event.currentTarget);
                          }
                      }
                    : undefined
            }
        >
            <TableCell />
            {visibleColumns.map((column) => (
                <TableCell
                    key={column.id}
                    className={
                        column.id === 'ip' || column.id === 'mac_address'
                            ? 'px-4 tabular-nums'
                            : 'whitespace-nowrap [&>span]:flex-nowrap'
                    }
                >
                    {column.id === 'sources' ? (
                        <div className="flex items-center gap-2">
                            <AvatarGroup
                                aria-label={`Fonti per ${ipAddress.ip}`}
                            >
                                <SnipeItIp source={ipAddress.snipe_it} avatar />
                                <MikrotikIndicator sources={sources} avatar />
                                <UnifiObservationIndicator
                                    observations={unifi}
                                    company={company}
                                    site={site}
                                    avatar
                                />
                            </AvatarGroup>
                            {sourceNotice ? (
                                <span className="text-xs text-muted-foreground">
                                    {sourceNotice}
                                </span>
                            ) : null}
                        </div>
                    ) : column.id === 'ip' ? (
                        <div className="flex items-center gap-2 whitespace-nowrap">
                            <CopyValue
                                value={ipAddress.ip}
                                className="py-1 tabular-nums"
                            />
                            {ipAddress.excluded ? (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span
                                            tabIndex={0}
                                            onClick={(event) =>
                                                event.stopPropagation()
                                            }
                                            role="img"
                                            aria-label="IP escluso dai suggerimenti"
                                            className="inline-flex rounded-sm text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <Ban
                                                className="size-3.5"
                                                aria-hidden="true"
                                            />
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        Escluso dai suggerimenti
                                    </TooltipContent>
                                </Tooltip>
                            ) : null}
                        </div>
                    ) : column.id === 'device' ? (
                        <DeviceAssociation
                            company={company}
                            site={site}
                            device={ipAddress.device}
                            item={ipAddress.device_interface}
                            label={ipAddress.assignment_label}
                        />
                    ) : column.id === 'hostname' ||
                      column.id === 'mac_address' ? (
                        <div className="flex flex-col items-start">
                            <MikrotikValue
                                showIndicator={false}
                                value={ipAddress[column.id]}
                                field={
                                    column.id === 'mac_address'
                                        ? 'mac'
                                        : 'hostname'
                                }
                                sources={sources}
                            />
                            <UnifiObservedValue
                                showIndicator={false}
                                observations={unifi}
                                company={company}
                                site={site}
                                value={ipAddress[column.id]}
                                field={
                                    column.id === 'mac_address'
                                        ? 'mac'
                                        : 'hostname_observed'
                                }
                            />
                        </div>
                    ) : column.id === 'status' ? (
                        ipAddress.status ? (
                            <Badge variant="secondary">
                                {ipAddress.status.name}
                            </Badge>
                        ) : (
                            ''
                        )
                    ) : column.id === 'tags' ? (
                        ipAddress.tags.length ? (
                            <div className="flex gap-1 whitespace-nowrap">
                                {ipAddress.tags.map((tag) => (
                                    <TagBadge key={tag.id} color={tag.color}>
                                        {tag.name}
                                    </TagBadge>
                                ))}
                            </div>
                        ) : (
                            ''
                        )
                    ) : (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="block max-w-lg truncate">
                                    {ipAddress[column.id] ?? ''}
                                </span>
                            </TooltipTrigger>
                            {ipAddress[column.id] ? (
                                <TooltipContent>
                                    {ipAddress[column.id]}
                                </TooltipContent>
                            ) : null}
                        </Tooltip>
                    )}
                </TableCell>
            ))}
            <TableCell />
        </TableRow>
    );
}
