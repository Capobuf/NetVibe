import { Network } from 'lucide-react';
import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return <Network {...props} fill="none" aria-hidden="true" />;
}
