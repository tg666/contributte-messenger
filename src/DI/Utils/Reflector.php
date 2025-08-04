<?php declare(strict_types = 1);

namespace Contributte\Messenger\DI\Utils;

use Contributte\Messenger\Exception\LogicalException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use function sprintf;
use function implode;
use function array_map;
use function array_merge;

final class Reflector
{

	/**
	 * @param class-string $class
	 * @return array<AsMessageHandler>
	 */
	public static function getMessageHandlers(string $class): array
	{
		$rc = new ReflectionClass($class);

		$classAttributes = array_map(
			static fn (ReflectionAttribute $attribute): AsMessageHandler => $attribute->newInstance(),
			$rc->getAttributes(AsMessageHandler::class),
		);

		$methodAttributes = [];

		foreach ($rc->getMethods() as $method) {
			$methodAttributes[] = array_map(
				static function (ReflectionAttribute $reflectionAttribute) use ($method): AsMessageHandler {
					$attribute = $reflectionAttribute->newInstance();
					$attribute->method = $method->getName();

					return $attribute;
				},
				$method->getAttributes(AsMessageHandler::class),
			);
		}

		return array_merge($classAttributes, ...$methodAttributes);
	}

	/**
	 * @param class-string $class
	 * @param array{method: string} $options
	 *
	 * @return list<string>
	 */
	public static function getMessageHandlerMessage(string $class, array $options): array
	{
		try {
			$rc = new ReflectionClass($class);
		} catch (ReflectionException $e) {
			throw new LogicalException(sprintf('Handler "%s" class not found', $class), 0, $e);
		}

		try {
			$rcMethod = $rc->getMethod($options['method']);
		} catch (ReflectionException) {
			throw new LogicalException(sprintf('Handler must have "%s::%s()" method.', $class, $options['method']));
		}

		if (0 === $rcMethod->getNumberOfRequiredParameters()) {
			throw new LogicalException(sprintf('Handler "%s::%s()" requires at least one argument, first one being the message it handles.', $class, $options['method']));
		}

		$parameters = $rcMethod->getParameters();

		/** @var ReflectionNamedType|ReflectionUnionType|ReflectionIntersectionType|null $type */
		$type = $parameters[0]->getType();

		if ($type === null) {
			throw new LogicalException(sprintf('Cannot detect parameter type for "%s::%s()."', $class, $options['method']));
		}

		if ($type instanceof ReflectionIntersectionType) {
			throw new LogicalException(sprintf('Intersection parameter type for "%s::%s() is not supported."', $class, $options['method']));
		}

		if ($type instanceof ReflectionUnionType) {
			$types = [];
			$invalidTypes = [];
			foreach ($type->getTypes() as $subType) {
				if (!($subType instanceof ReflectionIntersectionType) && !$subType->isBuiltin()) {
					$types[] = (string) $subType;
				} else {
					$invalidTypes[] = (string) $subType;
				}
			}

			if ([] !== $types) {
				return $types;
			}

			throw new LogicalException(sprintf('Type-hint of argument "$%s" in method "%s::%s()" must be a class , "%s" given.', $parameters[0]->getName(), $class, $options['method'], implode('|', $invalidTypes)));
		}

		if ($type->isBuiltin()) {
			throw new LogicalException(sprintf('Builtin parameter type for "%s::%s() is not supported."', $class, $options['method']));
		}

		return [$type->getName()];
	}

}
