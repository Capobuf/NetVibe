export function ipv4Number(value: string): number | null {
    if (!/^(0|[1-9]\d{0,2})(\.(0|[1-9]\d{0,2})){3}$/.test(value)) return null;
    const octets = value.split('.').map(Number);
    if (octets.some((octet) => octet > 255)) return null;
    return octets.reduce((address, octet) => address * 256 + octet, 0);
}

export function ipv4RangeSummary(cidr: string, startIp: string, endIp: string) {
    const [network, prefix] = cidr.split('/');
    const networkStart = ipv4Number(network)!;
    const total = 2 ** (32 - Number(prefix));
    const start = ipv4Number(startIp);
    const end = ipv4Number(endIp);

    if (
        start === null ||
        end === null ||
        start > end ||
        start < networkStart ||
        end >= networkStart + total
    )
        return null;

    return {
        count: end - start + 1,
        total,
        offset: ((start - networkStart) / total) * 100,
    };
}
