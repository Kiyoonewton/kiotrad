type LogoProps = {
  size?: number;
  label?: string;
  animated?: boolean;
  className?: string;
};

export function Logo({
  size = 40,
  label = "KioTrad",
  animated = true,
  className = "",
}: LogoProps) {
  return (
    <img
      className={`kiotrad-logo ${className}`.trim()}
      src={animated ? "/kiotrad-animated.svg" : "/kiotrad-mark.svg"}
      width={size}
      height={size}
      alt={label}
      aria-hidden={label === "" ? true : undefined}
      decoding="async"
    />
  );
}
